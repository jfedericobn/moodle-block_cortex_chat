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

/**
 * English language strings for block_cortex_chat.
 *
 * @package    block_cortex_chat
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Cortex course chat';

// Capabilities.
$string['cortex_chat:use'] = 'Ask questions in the Cortex course chat';
$string['cortex_chat:viewstatus'] = 'View Cortex course chat diagnostics';
$string['cortex_chat:manage'] = 'Configure the Cortex course chat block';
$string['cortex_chat:addinstance'] = 'Add a new Cortex course chat block';

// Block configuration form.
$string['config_title'] = 'Block title';
$string['config_title_help'] = 'An optional title to display instead of the default block name.';

// Chat UI.
$string['chatintro'] = 'Ask a question about this course. Answers come only from this course\'s material.';
$string['inputlabel'] = 'Your question';
$string['inputplaceholder'] = 'Ask about this course…';
$string['send'] = 'Send';
$string['sending'] = 'Thinking…';
$string['youlabel'] = 'You';
$string['assistantlabel'] = 'Course assistant';
$string['sourcesheading'] = 'Sources';
$string['disclaimer'] = 'AI-generated from course material. Verify important information.';
$string['clearchat'] = 'Clear conversation';
$string['charcount'] = '{$a->count}/{$a->max}';

// Policy (reuses Moodle AI acceptance).
$string['policyaccept'] = 'Accept';
$string['policydecline'] = 'Decline';

// Result/error messages shown to learners.
$string['error_generic'] = 'Something went wrong. Please try again in a moment.';
$string['error_serviceunavailable'] = 'The course knowledge service is temporarily unavailable. Please try again later.';
$string['error_aifailed'] = 'The assistant could not generate an answer right now. Please try again later.';
$string['error_ratelimited'] = 'You have sent too many questions recently. Please wait a little before asking again.';
$string['error_messagetoolong'] = 'Your question is too long. Please shorten it and try again.';
$string['error_emptymessage'] = 'Please type a question first.';
$string['error_policynotaccepted'] = 'You need to accept the AI usage policy before using the chat.';

// Unavailable states.
$string['unavailable_heading'] = 'Course chat is not available';
$string['unavailable_notconfigured'] = 'The Cortex course chat has not been configured for this site yet.';
$string['unavailable_coursedisabled'] = 'This course has not been enabled for Cortex knowledge.';
$string['unavailable_coursenotready'] = 'This course\'s knowledge base is not ready yet. Please check back later.';
$string['unavailable_ainotavailable'] = 'The AI assistant is not available for this course right now.';
$string['unavailable_default'] = 'The course chat is not available right now.';

// Staff diagnostics.
$string['staffdiagnostics'] = 'Staff diagnostics';
$string['diag_detail'] = 'Detail';
$string['diag_grounding'] = 'Last grounding state';

// Admin settings.
$string['settings_serviceurl'] = 'Cortex Service Plane URL';
$string['settings_serviceurl_desc'] = 'Base URL of the Cortex Service Plane reachable from this Moodle server, e.g. https://cortex.example:8001/ (no trailing /api/v1). Used for POST /api/v1/service/retrieve. This is deployed separately from the ingest plane and must be kept private to server-side requests.';
$string['settings_maxquestionlength'] = 'Maximum question length';
$string['settings_maxquestionlength_desc'] = 'Maximum number of characters allowed in a single question.';
$string['settings_maxpropositions'] = 'Maximum retrieved propositions';
$string['settings_maxpropositions_desc'] = 'Maximum number of retrieved course-material snippets passed to the AI as context.';
$string['settings_maxcontextchars'] = 'Maximum context characters';
$string['settings_maxcontextchars_desc'] = 'Maximum total characters of retrieved context passed to the AI, to avoid provider token overflow.';
$string['settings_ratelimit'] = 'Per-user request limit';
$string['settings_ratelimit_desc'] = 'Maximum number of questions a user may ask within the rate-limit window.';
$string['settings_ratewindow'] = 'Rate-limit window (seconds)';
$string['settings_ratewindow_desc'] = 'The rolling time window used for the per-user request limit.';
$string['settings_declinemessage'] = 'Course-material-only decline message';
$string['settings_declinemessage_desc'] = 'The fixed message shown when the course material does not contain an answer to the question.';
$string['default_declinemessage'] = 'I can only answer questions about this course\'s material, and I could not find anything relevant to your question. Try rephrasing it, or ask your teacher.';

// Privacy.
$string['privacy:metadata:cortex_service'] = 'To find relevant course material, the question you type is sent to the external Cortex knowledge service.';
$string['privacy:metadata:cortex_service:query'] = 'The question text you submit to the chat.';
$string['privacy:metadata:core_ai'] = 'The chat uses the Moodle AI subsystem to compose an answer from the retrieved course material. The composed prompt is handled under the AI subsystem\'s retention controls.';
