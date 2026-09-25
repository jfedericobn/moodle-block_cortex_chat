<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace block_cortex_chat\local;

use core_ai\aiactions\generate_text;
use core_ai\manager;
use local_cortex\local\course_state;

/**
 * Orchestrates the course-scoped chat pipeline:
 * (Cortex retrieval + live Moodle schedule) -> bounded prompt -> Moodle AI.
 *
 * The server derives the tenant id and reference code from the course's ready
 * local_cortex row. The browser never supplies either value, so it cannot
 * query another course's corpus.
 *
 * Two evidence sources are merged before generation:
 *  - Cortex retrieval, for conceptual course content;
 *  - {@see live_course_agent}, for the asking user's authoritative activity
 *    schedule (assignment/quiz dates and their personal overrides).
 *
 * The policy remains fail-closed: generation proceeds only if Cortex returns
 * usable grounding OR the live agent returns at least one visible fact. If
 * neither source has evidence, a fixed decline is returned and the AI provider
 * is never called. Live personal dates stay in the Moodle AI prompt and are
 * never written to the Cortex corpus.
 *
 * @package    block_cortex_chat
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_service {
    /** @var string An AI-formatted answer grounded in course material. */
    const STATUS_ANSWERED = 'answered';

    /** @var string Cortex had no grounded answer; fixed decline returned. */
    const STATUS_DECLINED = 'declined';

    /** @var string The chat cannot currently run (config/course/AI/policy). */
    const STATUS_UNAVAILABLE = 'unavailable';

    /** @var string A transient failure the learner may retry. */
    const STATUS_ERROR = 'error';

    /**
     * Maximum user question length passthrough for the UI/limit checks.
     *
     * @return int
     */
    public static function max_question_length(): int {
        return config::max_question_length();
    }

    /**
     * Determine whether the chat can operate for a course/user.
     *
     * Checks (in order): block configuration, course readiness, and Moodle AI
     * availability in this context. Per-user AI policy acceptance is enforced at
     * ask time (it is an explicit user action handled by the UI).
     *
     * @param int $courseid
     * @param \context $context Course context.
     * @param int $userid
     * @return array{available:bool,reason:string,detail:string,groundingstate:string}
     */
    public static function availability(int $courseid, \context $context, int $userid): array {
        if (!config::is_configured()) {
            return self::unavailable('notconfigured', 'Service URL and/or tenant id are not set.');
        }

        $state = course_state::get($courseid);
        if (!$state || empty($state->enabled)) {
            return self::unavailable('coursedisabled', 'Course is not enabled for Cortex knowledge.');
        }
        if ($state->status !== course_state::STATUS_READY || empty($state->referencecode)) {
            return self::unavailable('coursenotready', 'Course corpus status: ' . ($state->status ?? 'unknown') . '.');
        }

        if (!self::is_ai_available($context)) {
            return self::unavailable('ainotavailable', 'No enabled Moodle AI provider supports generate_text in this context.');
        }

        return [
            'available' => true,
            'reason' => '',
            'detail' => '',
            'groundingstate' => (string)($state->stage ?? ''),
        ];
    }

    /**
     * Run the retrieval + generation pipeline for one question.
     *
     * @param int $courseid
     * @param \context $context Course context.
     * @param int $userid
     * @param string $message The user question (already length-validated).
     * @return array{status:string,answer:string,sources:array,groundingstate:string,errorcode:string}
     */
    public static function ask(int $courseid, \context $context, int $userid, string $message): array {
        // Re-run availability server-side; the browser cannot be trusted.
        $availability = self::availability($courseid, $context, $userid);
        if (!$availability['available']) {
            return self::result(self::STATUS_UNAVAILABLE, '', [], '', $availability['reason']);
        }

        // Enforce Moodle AI policy acceptance (defense in depth; the UI also checks).
        if (!manager::get_user_policy_status($userid)) {
            return self::result(self::STATUS_UNAVAILABLE, '', [], '', 'policynotaccepted');
        }

        $state = course_state::get($courseid);
        $referencecode = (string)$state->referencecode;

        // Stage 1: retrieve grounded context from Cortex. A Cortex transport
        // error or non-200 does not hard-fail the request on its own: if the
        // live agent later has usable facts we can still answer the student.
        $groundingstate = 'declined';
        $propositions = [];
        $cortexunavailable = false;
        try {
            $response = service_client::instance()->retrieve($referencecode, $message);
            if ((int)$response['httpcode'] === 200) {
                $body = $response['body'];
                $groundingstate = (string)($body['grounding_state'] ?? 'declined');
                $propositions = is_array($body['propositions'] ?? null) ? $body['propositions'] : [];
            } else {
                $cortexunavailable = true;
            }
        } catch (\Throwable $e) {
            $cortexunavailable = true;
        }

        // Stage 1b: gather live course facts (assignment/quiz schedule) for this
        // user, in-process. Never lets a live-data failure break the pipeline.
        $liveprops = live_course_agent::collect($courseid, $userid);

        // Multi-source gate (still fail closed): proceed if Cortex has usable
        // grounding OR the live agent returned at least one visible fact.
        $cortexdeclined = self::should_decline($groundingstate, $propositions);
        if ($cortexdeclined && empty($liveprops)) {
            // With no live evidence, surface a retryable error when Cortex was
            // unavailable, otherwise the normal course-material-only decline.
            if ($cortexunavailable) {
                return self::result(self::STATUS_ERROR, '', [], '', 'serviceunavailable');
            }
            return self::result(self::STATUS_DECLINED, config::decline_message(), [], $groundingstate, '');
        }

        // If Cortex grounding was weak, do not pass its (rejected) propositions
        // to the model; answer from the live schedule alone.
        $cortexforprompt = $cortexdeclined ? [] : $propositions;

        // Bound the context passed downstream, merging both evidence sources.
        [$prompt, $sources] = self::build_prompt($message, $cortexforprompt, $liveprops);

        // Stage 2: format an answer with Moodle AI, grounded only in the context.
        try {
            $action = new generate_text(
                contextid: $context->id,
                userid: $userid,
                prompttext: $prompt,
            );
            $aimanager = \core\di::get(manager::class);
            $airesponse = $aimanager->process_action($action);
        } catch (\Throwable $e) {
            return self::result(self::STATUS_ERROR, '', [], $groundingstate, 'aifailed');
        }

        if (!$airesponse->get_success()) {
            return self::result(self::STATUS_ERROR, '', [], $groundingstate, 'aifailed');
        }

        $answer = (string)($airesponse->get_response_data()['generatedcontent'] ?? '');
        if (trim($answer) === '') {
            return self::result(self::STATUS_ERROR, '', [], $groundingstate, 'aifailed');
        }

        return self::result(self::STATUS_ANSWERED, $answer, $sources, $groundingstate, '');
    }

    /**
     * The Cortex-side grounding gate.
     *
     * Only "strong" or "qualified" grounding with at least one proposition
     * counts as usable course-content evidence. Everything else (including
     * "declined" and empty results) is treated as no Cortex evidence. The
     * overall decision to answer is made in {@see ask()}, which also considers
     * live evidence before falling back to the fixed decline.
     *
     * @param string $groundingstate Cortex grounding state.
     * @param array $propositions Retrieved propositions.
     * @return bool True if Cortex provided no usable grounded evidence.
     */
    public static function should_decline(string $groundingstate, array $propositions): bool {
        if (!in_array($groundingstate, ['strong', 'qualified'], true)) {
            return true;
        }
        return empty($propositions);
    }

    /**
     * Compose a bounded, grounded prompt and the ordered source list.
     *
     * Two evidence sources are merged into one numbered context so citations
     * ([1], [2], ...) map to the returned source list regardless of origin:
     *  - the LIVE COURSE SCHEDULE (authoritative per-user dates from Moodle),
     *    rendered first so it is protected by the character budget;
     *  - the COURSE MATERIAL (Cortex-retrieved course content), bounded by the
     *    proposition count.
     *
     * @param string $message The user question.
     * @param array $cortexprops Cortex propositions (each an assoc array).
     * @param array $liveprops Live Moodle propositions (each an assoc array).
     * @return array{0:string,1:array} [prompt, sources]
     */
    private static function build_prompt(string $message, array $cortexprops, array $liveprops = []): array {
        $maxprops = config::max_propositions();
        $maxchars = config::max_context_chars();

        $sources = [];
        $sourceindex = [];
        $used = 0;
        $number = 0;

        // Live schedule first: authoritative and small, so it is prioritised in
        // the shared character budget. Bounded by its own activity cap already.
        $livelines = [];
        foreach ($liveprops as $prop) {
            $text = trim((string)($prop['proposition'] ?? ''));
            if ($text === '') {
                continue;
            }
            $reference = trim((string)($prop['source_reference'] ?? ''));
            $entry = '[' . ($number + 1) . '] ' . $text;
            if ($reference !== '') {
                $entry .= ' (source: ' . $reference . ')';
            }
            if ($used + \core_text::strlen($entry) > $maxchars && !empty($livelines)) {
                break;
            }
            $used += \core_text::strlen($entry);
            $number++;
            $livelines[] = $entry;
            self::track_source($sources, $sourceindex, $reference);
        }

        // Course material next, bounded by the proposition count.
        $materiallines = [];
        $materialcount = 0;
        foreach ($cortexprops as $prop) {
            if ($materialcount >= $maxprops) {
                break;
            }
            $text = trim((string)($prop['proposition'] ?? ''));
            if ($text === '') {
                continue;
            }
            $reference = trim((string)($prop['source_reference'] ?? ''));
            $entry = '[' . ($number + 1) . '] ' . $text;
            if ($reference !== '') {
                $entry .= ' (source: ' . $reference . ')';
            }
            if ($used + \core_text::strlen($entry) > $maxchars && (!empty($materiallines) || !empty($livelines))) {
                break;
            }
            $used += \core_text::strlen($entry);
            $number++;
            $materiallines[] = $entry;
            $materialcount++;
            self::track_source($sources, $sourceindex, $reference);
        }

        $prompt = "You are a teaching assistant for a specific Moodle course. "
            . "Answer the student's question using ONLY the information provided below. "
            . "Do not use any outside or general knowledge.\n"
            . "- LIVE COURSE SCHEDULE contains this student's authoritative, up-to-date "
            . "dates (assignment and quiz open/due/close times), including any personal "
            . "extensions or overrides. Prefer it for any question about dates, deadlines, "
            . "or scheduling, even if the course material says otherwise.\n"
            . "- COURSE MATERIAL contains excerpts from the course content. Use it for "
            . "conceptual questions.\n"
            . "If neither section contains the answer, reply that the course material does "
            . "not cover it and suggest the student rephrase or ask their teacher. "
            . "Be concise, cite the numbered sources you used using their bracketed numbers, "
            . "and never invent sources or facts.\n";

        if (!empty($livelines)) {
            $prompt .= "\nLIVE COURSE SCHEDULE:\n" . implode("\n", $livelines) . "\n";
        }
        if (!empty($materiallines)) {
            $prompt .= "\nCOURSE MATERIAL:\n" . implode("\n", $materiallines) . "\n";
        }

        $prompt .= "\nSTUDENT QUESTION:\n" . $message;

        return [$prompt, $sources];
    }

    /**
     * Append a reference to the ordered, de-duplicated source list.
     *
     * @param array $sources Source list (modified in place).
     * @param array $sourceindex Seen-reference index (modified in place).
     * @param string $reference The source reference to record.
     */
    private static function track_source(array &$sources, array &$sourceindex, string $reference): void {
        if ($reference === '' || isset($sourceindex[$reference])) {
            return;
        }
        $sourceindex[$reference] = true;
        $sources[] = [
            'index' => count($sources) + 1,
            'reference' => $reference,
        ];
    }

    /**
     * Whether Moodle AI can run generate_text in this context.
     *
     * Mirrors the availability pattern used by aiplacement_courseassist.
     *
     * @param \context $context
     * @return bool
     */
    private static function is_ai_available(\context $context): bool {
        try {
            $manager = \core\di::get(manager::class);
            return $manager->is_action_available(generate_text::class)
                && $manager->is_action_enabled_in_context($context, generate_text::class);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Build an unavailable availability payload.
     *
     * @param string $reason Non-sensitive reason key.
     * @param string $detail Staff-only detail.
     * @return array{available:bool,reason:string,detail:string,groundingstate:string}
     */
    private static function unavailable(string $reason, string $detail): array {
        return [
            'available' => false,
            'reason' => $reason,
            'detail' => $detail,
            'groundingstate' => '',
        ];
    }

    /**
     * Build a structured ask() result.
     *
     * @param string $status One of the STATUS_* constants.
     * @param string $answer
     * @param array $sources
     * @param string $groundingstate
     * @param string $errorcode Non-sensitive error/reason category.
     * @return array{status:string,answer:string,sources:array,groundingstate:string,errorcode:string}
     */
    private static function result(
        string $status,
        string $answer,
        array $sources,
        string $groundingstate,
        string $errorcode
    ): array {
        return [
            'status' => $status,
            'answer' => $answer,
            'sources' => $sources,
            'groundingstate' => $groundingstate,
            'errorcode' => $errorcode,
        ];
    }
}
