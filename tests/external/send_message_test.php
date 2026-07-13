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

/**
 * Tests for the send_message external function.
 *
 * @package    block_cortex_chat
 * @covers     \block_cortex_chat\external\send_message
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class send_message_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;

    /** @var \context_course */
    private $context;

    /** @var \stdClass */
    private $teacher;

    /**
     * Common fixtures: a course, its context, and an enrolled editing teacher.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->context = \context_course::instance($this->course->id);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
    }

    /**
     * A non-course context is rejected before any processing.
     */
    public function test_rejects_non_course_context(): void {
        $this->setUser($this->teacher);
        $result = send_message::execute(\context_system::instance()->id, 'Hello?');
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('badcontext', $result['errorcode']);
    }

    /**
     * The use capability is required, even for an enrolled user.
     */
    public function test_requires_use_capability(): void {
        global $DB;
        // Enrol a student (who normally has the capability), then prohibit it so
        // context validation still passes but the capability check fails.
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        assign_capability('block/cortex_chat:use', CAP_PROHIBIT, $studentrole->id, $this->context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        send_message::execute($this->context->id, 'Hello?');
    }

    /**
     * An empty question is rejected.
     */
    public function test_rejects_empty_message(): void {
        $this->setUser($this->teacher);
        $result = send_message::execute($this->context->id, '   ');
        $this->assertSame('error', $result['status']);
        $this->assertSame('emptymessage', $result['errorcode']);
    }

    /**
     * A question longer than the configured maximum is rejected.
     */
    public function test_rejects_message_too_long(): void {
        set_config('maxquestionlength', 10, 'block_cortex_chat');
        $this->setUser($this->teacher);

        $result = send_message::execute($this->context->id, str_repeat('a', 11));
        $this->assertSame('error', $result['status']);
        $this->assertSame('messagetoolong', $result['errorcode']);
    }

    /**
     * The per-user rate limit is enforced.
     */
    public function test_enforces_rate_limit(): void {
        set_config('ratelimit', 1, 'block_cortex_chat');
        set_config('ratewindow', 3600, 'block_cortex_chat');
        $this->setUser($this->teacher);

        // First request is allowed (falls through to an unavailable result
        // because the course is not ready, but it consumes the budget).
        $first = send_message::execute($this->context->id, 'First question?');
        $this->assertNotSame('error', $first['status']);

        // Second request within the window is rate limited.
        $second = send_message::execute($this->context->id, 'Second question?');
        $this->assertSame('error', $second['status']);
        $this->assertSame('ratelimited', $second['errorcode']);
    }

    /**
     * With a valid request but an unprepared course, the function fails closed.
     */
    public function test_unready_course_is_unavailable(): void {
        $this->setUser($this->teacher);
        $result = send_message::execute($this->context->id, 'Anything?');
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('', $result['answer']);
        $this->assertSame([], $result['sources']);
    }
}
