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
 * Sliding-window per-user rate limiter backed by a MUC application cache.
 *
 * @package    block_cortex_chat
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_limiter {
    /**
     * Record a request and report whether the user is within the limit.
     *
     * The check and the record happen together so a single call both accounts
     * for the request and enforces the ceiling.
     *
     * @param int $userid The user making the request.
     * @param int|null $now Optional current timestamp (for testing).
     * @return bool True if the request is allowed, false if the limit is exceeded.
     */
    public static function attempt(int $userid, ?int $now = null): bool {
        $now = $now ?? time();
        $limit = config::rate_limit();
        $window = config::rate_window();

        $cache = \cache::make('block_cortex_chat', 'ratelimit');
        $key = (string)$userid;

        $timestamps = $cache->get($key);
        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        // Drop entries outside the rolling window.
        $cutoff = $now - $window;
        $timestamps = array_values(array_filter($timestamps, static function ($ts) use ($cutoff): bool {
            return (int)$ts > $cutoff;
        }));

        if (count($timestamps) >= $limit) {
            // Persist the pruned list so the window keeps sliding.
            $cache->set($key, $timestamps);
            return false;
        }

        $timestamps[] = $now;
        $cache->set($key, $timestamps);
        return true;
    }
}
