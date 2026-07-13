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

namespace block_cortex_chat\external;

use block_cortex_chat\local\chat_service;
use block_cortex_chat\local\config;
use block_cortex_chat\local\rate_limiter;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * External function backing the chat block: retrieve from Cortex, generate via Moodle AI.
 *
 * @package    block_cortex_chat
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_message extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Course context id'),
            'message' => new external_value(PARAM_TEXT, 'The student question'),
        ]);
    }

    /**
     * Ask a course-scoped question.
     *
     * @param int $contextid
     * @param string $message
     * @return array
     */
    public static function execute(int $contextid, string $message): array {
        global $USER;

        [
            'contextid' => $contextid,
            'message' => $message,
        ] = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'message' => $message,
        ]);

        // Context validation (also enforces AJAX session/login).
        $context = \context::instance_by_id($contextid);
        self::validate_context($context);

        // Must be a course context.
        if ($context->contextlevel != CONTEXT_COURSE) {
            return self::error_result('badcontext');
        }
        $courseid = (int)$context->instanceid;

        // Capability check.
        require_capability('block/cortex_chat:use', $context);

        // Message content validation.
        $message = trim($message);
        if ($message === '') {
            return self::error_result('emptymessage');
        }
        if (\core_text::strlen($message) > config::max_question_length()) {
            return self::error_result('messagetoolong');
        }

        // Per-user rate limit.
        if (!rate_limiter::attempt((int)$USER->id)) {
            return self::error_result('ratelimited');
        }

        // Run the pipeline.
        $result = chat_service::ask($courseid, $context, (int)$USER->id, $message);

        return [
            'status' => $result['status'],
            'answer' => $result['answer'],
            'sources' => $result['sources'],
            'groundingstate' => $result['groundingstate'],
            'errorcode' => $result['errorcode'],
        ];
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'answered | declined | unavailable | error'),
            'answer' => new external_value(PARAM_RAW, 'The generated answer or decline message', VALUE_DEFAULT, ''),
            'sources' => new external_multiple_structure(
                new external_single_structure([
                    'index' => new external_value(PARAM_INT, 'Citation number'),
                    'reference' => new external_value(PARAM_TEXT, 'Human-readable source reference'),
                ]),
                'Ordered, unique source references',
                VALUE_DEFAULT,
                []
            ),
            'groundingstate' => new external_value(PARAM_ALPHA, 'Cortex grounding state', VALUE_DEFAULT, ''),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Non-sensitive reason category', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Build a uniform error/unavailable result.
     *
     * @param string $errorcode
     * @return array
     */
    private static function error_result(string $errorcode): array {
        $status = in_array($errorcode, ['badcontext', 'policynotaccepted'], true)
            ? chat_service::STATUS_UNAVAILABLE
            : chat_service::STATUS_ERROR;
        return [
            'status' => $status,
            'answer' => '',
            'sources' => [],
            'groundingstate' => '',
            'errorcode' => $errorcode,
        ];
    }
}
