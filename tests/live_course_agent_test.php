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

use block_cortex_chat\local\live_course_agent;

/**
 * Tests for the live Moodle course agent.
 *
 * @package    block_cortex_chat
 * @covers     \block_cortex_chat\local\live_course_agent
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class live_course_agent_test extends \advanced_testcase {

    /**
     * Enrol a fresh student in a fresh course and act as that user.
     *
     * @return array{0:\stdClass,1:\stdClass} [course, user]
     */
    private function make_course_and_student(): array {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        return [$course, $user];
    }

    /**
     * Assignment and quiz dates the student can see are returned as propositions.
     */
    public function test_collect_returns_assign_and_quiz_dates(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->make_course_and_student();

        $due = time() + WEEKSECS;
        $close = time() + 2 * WEEKSECS;

        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'name' => 'Essay 1',
            'duedate' => $due,
        ]);
        $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'name' => 'Midterm',
            'timeclose' => $close,
        ]);

        $props = live_course_agent::collect($course->id, $user->id);

        // At least one proposition per activity, all flagged as live.
        $this->assertNotEmpty($props);
        foreach ($props as $prop) {
            $this->assertSame('live', $prop['origin']);
            $this->assertArrayHasKey('proposition', $prop);
            $this->assertArrayHasKey('source_reference', $prop);
        }

        $blob = implode("\n", array_column($props, 'proposition'));
        $refs = implode("\n", array_column($props, 'source_reference'));

        $this->assertStringContainsString('Essay 1', $blob);
        $this->assertStringContainsString('Midterm', $blob);
        $this->assertStringContainsString('Moodle live: Assignment "Essay 1"', $refs);
        $this->assertStringContainsString('Moodle live: Quiz "Midterm"', $refs);
    }

    /**
     * Hidden activities are never reported.
     */
    public function test_collect_excludes_hidden_activities(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->make_course_and_student();

        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'name' => 'Secret Assignment',
            'duedate' => time() + WEEKSECS,
            'visible' => 0,
        ]);

        $props = live_course_agent::collect($course->id, $user->id);
        $blob = implode("\n", array_column($props, 'proposition'));
        $this->assertStringNotContainsString('Secret Assignment', $blob);
    }

    /**
     * Activities with no configured dates are still reported by existence, so
     * "does this course have an exam / a quiz" can be answered.
     */
    public function test_collect_reports_dateless_activity_existence(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->make_course_and_student();

        // No timeopen / timeclose set (mirrors the real "Summative Quiz").
        $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'name' => 'Summative Quiz',
        ]);

        $props = live_course_agent::collect($course->id, $user->id);
        $blob = implode("\n", array_column($props, 'proposition'));

        // The activity is reported as existing even without any dates.
        $this->assertStringContainsString('Summative Quiz', $blob);
        $this->assertStringContainsString('exists in this course', $blob);
    }

    /**
     * When live data is disabled, the agent returns nothing.
     */
    public function test_collect_disabled_returns_empty(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->make_course_and_student();
        set_config('livedataenabled', 0, 'block_cortex_chat');

        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'name' => 'Essay 1',
            'duedate' => time() + WEEKSECS,
        ]);

        $this->assertSame([], live_course_agent::collect($course->id, $user->id));
    }

    /**
     * The number of reported activities is bounded by maxliveactivities.
     */
    public function test_collect_respects_activity_cap(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->make_course_and_student();
        set_config('maxliveactivities', 2, 'block_cortex_chat');

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        for ($i = 0; $i < 5; $i++) {
            $generator->create_instance([
                'course' => $course->id,
                'name' => 'Assignment ' . $i,
                'duedate' => time() + WEEKSECS,
            ]);
        }

        $props = live_course_agent::collect($course->id, $user->id);

        // Distinct activities referenced must not exceed the cap (each activity
        // may contribute more than one date, so count unique references).
        $uniquerefs = array_unique(array_column($props, 'source_reference'));
        $this->assertLessThanOrEqual(2, count($uniquerefs));
    }
}
