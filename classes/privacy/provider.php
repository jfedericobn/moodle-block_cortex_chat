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

namespace block_cortex_chat\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy metadata for block_cortex_chat.
 *
 * The block stores no personal data in Moodle database tables. It transmits the
 * user's typed question to the external Cortex Service Plane for retrieval, and
 * it hands a bounded prompt to the Moodle AI subsystem, which records the prompt
 * under its own retention controls. A short-lived rate-limit counter is kept in
 * the cache only.
 *
 * The prompt may also include the asking user's own effective activity schedule
 * (assignment/quiz dates and their personal overrides), read live from Moodle
 * for activities the user can already see. This live schedule is used only to
 * compose the local Moodle AI prompt; it is never transmitted to Cortex and is
 * not stored by this plugin.
 *
 * @package    block_cortex_chat
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider {

    /**
     * Describe the data this plugin transmits/uses.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        // Question text sent to the external Cortex Service Plane for retrieval.
        $collection->add_external_location_link(
            'cortex_service',
            [
                'query' => 'privacy:metadata:cortex_service:query',
            ],
            'privacy:metadata:cortex_service'
        );

        // The bounded prompt is processed by the Moodle AI subsystem.
        $collection->add_subsystem_link(
            'core_ai',
            [],
            'privacy:metadata:core_ai'
        );

        return $collection;
    }
}
