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

namespace block_cortex_chat;

use block_cortex_chat\local\rate_limiter;

/**
 * Tests for the per-user sliding-window rate limiter.
 *
 * @package    block_cortex_chat
 * @covers     \block_cortex_chat\local\rate_limiter
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rate_limiter_test extends \advanced_testcase {

    /**
     * Requests up to the limit are allowed; the next one is refused.
     */
    public function test_allows_up_to_limit_then_blocks(): void {
        $this->resetAfterTest();
        set_config('ratelimit', 3, 'block_cortex_chat');
        set_config('ratewindow', 3600, 'block_cortex_chat');

        $userid = 42;
        $now = 1000000;

        $this->assertTrue(rate_limiter::attempt($userid, $now));
        $this->assertTrue(rate_limiter::attempt($userid, $now + 1));
        $this->assertTrue(rate_limiter::attempt($userid, $now + 2));
        // Fourth request within the window is blocked.
        $this->assertFalse(rate_limiter::attempt($userid, $now + 3));
    }

    /**
     * The window slides: old requests age out and free up capacity.
     */
    public function test_window_slides(): void {
        $this->resetAfterTest();
        set_config('ratelimit', 2, 'block_cortex_chat');
        set_config('ratewindow', 100, 'block_cortex_chat');

        $userid = 7;
        $start = 500000;

        $this->assertTrue(rate_limiter::attempt($userid, $start));
        $this->assertTrue(rate_limiter::attempt($userid, $start + 10));
        $this->assertFalse(rate_limiter::attempt($userid, $start + 20));

        // Move past the window relative to the first two requests.
        $this->assertTrue(rate_limiter::attempt($userid, $start + 111));
    }

    /**
     * Different users have independent budgets.
     */
    public function test_users_are_independent(): void {
        $this->resetAfterTest();
        set_config('ratelimit', 1, 'block_cortex_chat');
        set_config('ratewindow', 3600, 'block_cortex_chat');

        $now = 200000;
        $this->assertTrue(rate_limiter::attempt(1, $now));
        $this->assertFalse(rate_limiter::attempt(1, $now + 1));
        // Second user is unaffected.
        $this->assertTrue(rate_limiter::attempt(2, $now + 1));
    }
}
