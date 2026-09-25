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

/**
 * In-process live course agent.
 *
 * Reads the asking user's visible assignments and quizzes directly from Moodle
 * at chat time - their existence, whether they are graded, and the user's
 * effective dates - and returns them as bounded "propositions" that share the
 * shape used by Cortex retrieval, so the chat orchestrator can merge both
 * sources into a single grounded prompt.
 *
 * Design constraints (deliberate):
 * - Runs as the asking user. It only reports course modules that user can see
 *   (`cm_info::$uservisible`) and only that user's effective dates (module
 *   defaults plus their user/group overrides, which Moodle applies to the
 *   current session user in `mod_*_cm_info_dynamic`).
 * - It never reports grades, gradebook history, completion, submissions, or any
 *   other learner's data.
 * - It performs no HTTP call to Cortex and no external web service; it is a
 *   read-only, in-request Moodle query.
 * - Its output stays inside the Moodle AI prompt. It is never written to the
 *   Cortex corpus.
 *
 * @package    block_cortex_chat
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_course_agent {
    /** @var string[] Module types this agent understands. */
    const SUPPORTED_MODULES = ['assign', 'quiz'];

    /** @var string Marker for the origin of a live proposition. */
    const ORIGIN = 'live';

    /**
     * Collect the asking user's visible activity schedule for a course.
     *
     * @param int $courseid The course id.
     * @param int $userid The asking user's id (the current session user).
     * @return array List of propositions, each:
     *               ['proposition' => string, 'source_reference' => string, 'origin' => 'live'].
     */
    public static function collect(int $courseid, int $userid): array {
        if (!config::live_data_enabled()) {
            return [];
        }

        try {
            $course = get_course($courseid);
            $modinfo = get_fast_modinfo($course, $userid);
        } catch (\Throwable $e) {
            // Never let a live-data failure break the chat pipeline.
            return [];
        }

        $maxactivities = config::max_live_activities();
        $propositions = [];
        $activities = 0;

        foreach (self::SUPPORTED_MODULES as $modname) {
            foreach ($modinfo->get_instances_of($modname) as $cm) {
                if ($activities >= $maxactivities) {
                    return $propositions;
                }

                // Only activities this user is allowed to see.
                if (!$cm->uservisible) {
                    continue;
                }

                $entries = self::activity_propositions($cm, $userid);
                if (empty($entries)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    $propositions[] = $entry;
                }
                $activities++;
            }
        }

        return $propositions;
    }

    /**
     * Build the propositions describing one activity for the user.
     *
     * Always emits an existence/gradedness summary so questions about which
     * activities or exams a course has can be answered even when no dates are
     * configured, then adds one row per effective date when dates exist.
     *
     * @param \cm_info $cm The course module (already known to be user-visible).
     * @param int $userid The asking user's id.
     * @return array Zero or more proposition rows for this activity.
     */
    private static function activity_propositions(\cm_info $cm, int $userid): array {
        $name = trim((string)$cm->get_formatted_name());
        if ($name === '') {
            $name = trim((string)$cm->name);
        }
        if ($name === '') {
            return [];
        }

        $typename = self::module_type_label($cm->modname);
        $reference = 'Moodle live: ' . $typename . ' "' . $name . '"';

        $rows = [];

        // Existence + gradedness summary (course configuration, not learner data).
        $rows[] = [
            'proposition' => self::summary_text($cm, $typename, $name),
            'source_reference' => $reference,
            'origin' => self::ORIGIN,
        ];

        // Effective dates for this user: module defaults plus the user's own
        // user/group overrides, resolved by the module's dates provider.
        try {
            $dates = \core\activity_dates::get_dates_for_module($cm, $userid);
        } catch (\Throwable $e) {
            $dates = [];
        }

        foreach ($dates as $date) {
            $timestamp = (int)($date['timestamp'] ?? 0);
            if ($timestamp <= 0) {
                continue;
            }
            $label = trim((string)($date['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            // Strip any trailing colon Moodle date labels sometimes carry.
            $label = rtrim($label, ': ');
            $formatted = userdate($timestamp);

            $rows[] = [
                'proposition' => $typename . ' "' . $name . '" - ' . $label . ': '
                    . $formatted . ' (this is your effective date for this course).',
                'source_reference' => $reference,
                'origin' => self::ORIGIN,
            ];
        }

        return $rows;
    }

    /**
     * Compose the existence/gradedness summary line for an activity.
     *
     * Gradedness comes from the activity's grade item (course configuration),
     * not from any learner's grade. If it cannot be determined it is omitted.
     *
     * @param \cm_info $cm The course module.
     * @param string $typename Human-readable module type label.
     * @param string $name The activity name.
     * @return string
     */
    private static function summary_text(\cm_info $cm, string $typename, string $name): string {
        // Keep the existence fact and the gradedness fact as two short,
        // standalone declarative sentences rather than one clause with a
        // trailing parenthetical. Models are more likely to use every fact
        // reliably when each is its own simple sentence.
        $sentences = [$typename . ' "' . $name . '" exists in this course.'];

        try {
            // grade_item is a legacy (non-autoloaded) class; it must be required
            // explicitly, otherwise lookups can fail intermittently depending on
            // what else has already been loaded in the request.
            global $CFG;
            require_once($CFG->libdir . '/gradelib.php');
            $gradeitem = \grade_item::fetch([
                'itemtype' => 'mod',
                'itemmodule' => $cm->modname,
                'iteminstance' => $cm->instance,
                'itemnumber' => 0,
                'courseid' => $cm->course,
            ]);
        } catch (\Throwable $e) {
            $gradeitem = false;
        }

        if ($gradeitem) {
            $gradetype = (int)$gradeitem->gradetype;
            if ($gradetype === GRADE_TYPE_VALUE || $gradetype === GRADE_TYPE_SCALE) {
                if ($gradetype === GRADE_TYPE_VALUE && (float)$gradeitem->grademax > 0) {
                    $max = rtrim(rtrim(number_format((float)$gradeitem->grademax, 2), '0'), '.');
                    $sentences[] = 'This ' . strtolower($typename) . ' IS GRADED, with a maximum grade of '
                        . $max . '.';
                } else {
                    $sentences[] = 'This ' . strtolower($typename) . ' IS GRADED.';
                }
            } else {
                $sentences[] = 'This ' . strtolower($typename) . ' is NOT graded.';
            }
        }

        return implode(' ', $sentences);
    }

    /**
     * Human-readable label for a supported module type.
     *
     * @param string $modname The module name (e.g. 'assign').
     * @return string
     */
    private static function module_type_label(string $modname): string {
        // Use the module's own display name where available; fall back to a
        // capitalised modname so an unknown type still renders sensibly.
        if (get_string_manager()->string_exists('pluginname', 'mod_' . $modname)) {
            return get_string('pluginname', 'mod_' . $modname);
        }
        return ucfirst($modname);
    }
}
