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

use block_cortex_chat\local\chat_service;

/**
 * Course-scoped Cortex RAG chat block.
 *
 * Renders a chat widget that answers only from the enrolled course's Cortex
 * corpus. Retrieval happens in Cortex; answer formatting happens through the
 * Moodle AI subsystem. The block never exposes tenant or reference identifiers
 * to the browser.
 *
 * @package    block_cortex_chat
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_cortex_chat extends block_base {

    /**
     * Initialise the block.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_cortex_chat');
    }

    /**
     * Allow an admin-provided title override.
     */
    public function specialization(): void {
        if (isset($this->config->title) && trim((string)$this->config->title) !== '') {
            $this->title = format_string($this->config->title, true, ['context' => $this->context]);
        } else {
            $this->title = get_string('pluginname', 'block_cortex_chat');
        }
    }

    /**
     * This block only makes sense inside a single course.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return [
            'course-view' => true,
            'site' => false,
            'my' => false,
            'course-view-social' => false,
        ];
    }

    /**
     * Only one chat block per course is meaningful.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * Expose an instance configuration form (title override).
     *
     * @return bool
     */
    public function has_config(): bool {
        return true;
    }

    /**
     * Build the block body.
     *
     * @return stdClass|null
     */
    public function get_content(): ?stdClass {
        global $OUTPUT, $USER;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        // Resolve the course context. The block is course-scoped; anything else
        // (site, user dashboard) renders nothing.
        //
        // Note: read $this->page->course into a local first. moodle_page exposes
        // "course" via a magic __get, and calling empty() directly on the magic
        // property triggers __isset (undefined => false), which would wrongly
        // report the course as empty on every request.
        $course = $this->page->course;
        if (empty($course) || $course->id == SITEID) {
            return $this->content;
        }
        $courseid = (int)$course->id;
        $coursecontext = context_course::instance($courseid);

        // Learners and staff need the "use" capability to see the chat at all.
        if (!has_capability('block/cortex_chat:use', $coursecontext)) {
            return $this->content;
        }

        $isstaff = has_capability('block/cortex_chat:viewstatus', $coursecontext);

        // Determine availability (course readiness + Moodle AI availability).
        $availability = chat_service::availability($courseid, $coursecontext, $USER->id);

        if (!$availability['available']) {
            $reasonkey = 'unavailable_' . $availability['reason'];
            $sm = get_string_manager();
            $message = $sm->string_exists($reasonkey, 'block_cortex_chat')
                ? get_string($reasonkey, 'block_cortex_chat')
                : get_string('unavailable_default', 'block_cortex_chat');
            $this->content->text = $OUTPUT->render_from_template('block_cortex_chat/unavailable', [
                'message' => $message,
                'isstaff' => $isstaff,
                'detail' => $isstaff ? $availability['detail'] : '',
            ]);
            return $this->content;
        }

        // Render the interactive chat shell and bootstrap the AMD module.
        $templatecontext = [
            'contextid' => $coursecontext->id,
            'courseid' => $courseid,
            'maxlength' => chat_service::max_question_length(),
            'isstaff' => $isstaff,
            'groundingstate' => $isstaff ? ($availability['groundingstate'] ?? '') : '',
        ];
        $this->content->text = $OUTPUT->render_from_template('block_cortex_chat/chat', $templatecontext);

        $this->page->requires->js_call_amd('block_cortex_chat/chat', 'init', [
            [
                'contextid' => $coursecontext->id,
                'courseid' => $courseid,
                'maxlength' => chat_service::max_question_length(),
            ],
        ]);

        return $this->content;
    }
}
