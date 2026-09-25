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
 * Admin settings for block_cortex_chat.
 *
 * @package    block_cortex_chat
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Cortex Service Plane base URL. Independent of the local_cortex ingest URL:
    // the Service Plane is deployed separately (commonly on a different port).
    $settings->add(new admin_setting_configtext(
        'block_cortex_chat/serviceurl',
        get_string('settings_serviceurl', 'block_cortex_chat'),
        get_string('settings_serviceurl_desc', 'block_cortex_chat'),
        '',
        PARAM_URL
    ));

    // Maximum length of a single user question (characters).
    $settings->add(new admin_setting_configtext(
        'block_cortex_chat/maxquestionlength',
        get_string('settings_maxquestionlength', 'block_cortex_chat'),
        get_string('settings_maxquestionlength_desc', 'block_cortex_chat'),
        '1000',
        PARAM_INT
    ));

    // Maximum number of retrieved propositions passed to Moodle AI.
    $settings->add(new admin_setting_configtext(
        'block_cortex_chat/maxpropositions',
        get_string('settings_maxpropositions', 'block_cortex_chat'),
        get_string('settings_maxpropositions_desc', 'block_cortex_chat'),
        '8',
        PARAM_INT
    ));

    // Maximum total characters of retrieved context passed to Moodle AI.
    $settings->add(new admin_setting_configtext(
        'block_cortex_chat/maxcontextchars',
        get_string('settings_maxcontextchars', 'block_cortex_chat'),
        get_string('settings_maxcontextchars_desc', 'block_cortex_chat'),
        '6000',
        PARAM_INT
    ));

    // Per-user request cap within the rolling window.
    $settings->add(new admin_setting_configtext(
        'block_cortex_chat/ratelimit',
        get_string('settings_ratelimit', 'block_cortex_chat'),
        get_string('settings_ratelimit_desc', 'block_cortex_chat'),
        '20',
        PARAM_INT
    ));

    // Rolling rate-limit window in seconds.
    $settings->add(new admin_setting_configtext(
        'block_cortex_chat/ratewindow',
        get_string('settings_ratewindow', 'block_cortex_chat'),
        get_string('settings_ratewindow_desc', 'block_cortex_chat'),
        '3600',
        PARAM_INT
    ));

    // Whether the live Moodle course agent augments answers with the user's
    // effective assignment/quiz schedule at chat time.
    $settings->add(new admin_setting_configcheckbox(
        'block_cortex_chat/livedataenabled',
        get_string('settings_livedataenabled', 'block_cortex_chat'),
        get_string('settings_livedataenabled_desc', 'block_cortex_chat'),
        '1'
    ));

    // Maximum number of activities the live agent reports into the prompt.
    $settings->add(new admin_setting_configtext(
        'block_cortex_chat/maxliveactivities',
        get_string('settings_maxliveactivities', 'block_cortex_chat'),
        get_string('settings_maxliveactivities_desc', 'block_cortex_chat'),
        '30',
        PARAM_INT
    ));

    // The fixed message shown when Cortex declines / returns no course material.
    $settings->add(new admin_setting_configtextarea(
        'block_cortex_chat/declinemessage',
        get_string('settings_declinemessage', 'block_cortex_chat'),
        get_string('settings_declinemessage_desc', 'block_cortex_chat'),
        get_string('default_declinemessage', 'block_cortex_chat'),
        PARAM_TEXT
    ));
}
