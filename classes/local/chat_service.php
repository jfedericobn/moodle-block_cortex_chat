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
 * Orchestrates the course-scoped RAG pipeline:
 * Cortex retrieval -> bounded prompt -> Moodle AI generation.
 *
 * The server derives the tenant id and reference code from the course's ready
 * local_cortex row. The browser never supplies either value, so it cannot
 * query another course's corpus. The policy is fail-closed: a declined or empty
 * Cortex grounding returns a course-material-only message and never reaches the
 * AI provider.
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

        // Stage 1: retrieve grounded context from Cortex.
        try {
            $response = service_client::instance()->retrieve($referencecode, $message);
        } catch (\Throwable $e) {
            return self::result(self::STATUS_ERROR, '', [], '', 'serviceunavailable');
        }

        if ((int)$response['httpcode'] !== 200) {
            return self::result(self::STATUS_ERROR, '', [], '', 'serviceunavailable');
        }

        $body = $response['body'];
        $groundingstate = (string)($body['grounding_state'] ?? 'declined');
        $propositions = is_array($body['propositions'] ?? null) ? $body['propositions'] : [];

        // Stage 1 gate (fail closed): only strong/qualified grounding with
        // propositions proceeds to AI generation.
        if (self::should_decline($groundingstate, $propositions)) {
            return self::result(self::STATUS_DECLINED, config::decline_message(), [], $groundingstate, '');
        }

        // Bound the context passed downstream.
        [$prompt, $sources] = self::build_prompt($message, $propositions);

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
     * The fail-closed retrieval gate.
     *
     * Only "strong" or "qualified" grounding with at least one proposition is
     * allowed through to AI generation. Everything else (including "declined"
     * and empty results) is refused so the assistant never answers without
     * grounded course material.
     *
     * @param string $groundingstate Cortex grounding state.
     * @param array $propositions Retrieved propositions.
     * @return bool True if the request must be declined.
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
     * @param string $message The user question.
     * @param array $propositions Cortex propositions (each an assoc array).
     * @return array{0:string,1:array} [prompt, sources]
     */
    private static function build_prompt(string $message, array $propositions): array {
        $maxprops = config::max_propositions();
        $maxchars = config::max_context_chars();

        $lines = [];
        $sources = [];
        $sourceindex = [];
        $used = 0;
        $index = 0;

        foreach ($propositions as $prop) {
            if ($index >= $maxprops) {
                break;
            }
            $text = trim((string)($prop['proposition'] ?? ''));
            if ($text === '') {
                continue;
            }
            $reference = trim((string)($prop['source_reference'] ?? ''));

            $entry = '[' . ($index + 1) . '] ' . $text;
            if ($reference !== '') {
                $entry .= ' (source: ' . $reference . ')';
            }

            // Enforce the total context character budget.
            if ($used + \core_text::strlen($entry) > $maxchars && !empty($lines)) {
                break;
            }
            $used += \core_text::strlen($entry);
            $lines[] = $entry;

            // Track unique, ordered sources for citation output.
            if ($reference !== '' && !isset($sourceindex[$reference])) {
                $sourceindex[$reference] = true;
                $sources[] = [
                    'index' => count($sources) + 1,
                    'reference' => $reference,
                ];
            }
            $index++;
        }

        $context = implode("\n", $lines);

        $prompt = "You are a teaching assistant for a specific Moodle course. "
            . "Answer the student's question using ONLY the course material provided below. "
            . "Do not use any outside or general knowledge. "
            . "If the provided material does not contain the answer, reply that the course "
            . "material does not cover it and suggest the student rephrase or ask their teacher. "
            . "Be concise, cite the numbered sources you used in square brackets like [1], and "
            . "never invent sources or facts.\n\n"
            . "COURSE MATERIAL:\n"
            . $context . "\n\n"
            . "STUDENT QUESTION:\n"
            . $message;

        return [$prompt, $sources];
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
