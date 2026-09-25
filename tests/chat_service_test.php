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

use block_cortex_chat\local\chat_service;
use local_cortex\local\course_state;

/**
 * Tests for the course-scoped RAG chat service.
 *
 * @package    block_cortex_chat
 * @covers     \block_cortex_chat\local\chat_service
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chat_service_test extends \advanced_testcase {

    /**
     * Configure the site with a Service URL and tenant.
     */
    private function configure_site(): void {
        set_config('serviceurl', 'https://cortex.example:8001', 'block_cortex_chat');
        set_config('tenantid', 'tenant-demo', 'local_cortex');
    }

    /**
     * Mark a course as ready with a reference code.
     *
     * @param int $courseid
     */
    private function make_course_ready(int $courseid): void {
        global $DB;
        course_state::set_enabled($courseid, true);
        $record = course_state::get($courseid);
        $record->status = course_state::STATUS_READY;
        $record->referencecode = 'REF-' . $courseid;
        $DB->update_record(course_state::TABLE, $record);
    }

    /**
     * The fail-closed gate: only strong/qualified grounding with propositions passes.
     */
    public function test_should_decline_gate(): void {
        $props = [['proposition' => 'x', 'source_reference' => 'Topic 1']];

        // Grounded states with propositions are allowed.
        $this->assertFalse(chat_service::should_decline('strong', $props));
        $this->assertFalse(chat_service::should_decline('qualified', $props));

        // Declined or unknown states are refused.
        $this->assertTrue(chat_service::should_decline('declined', $props));
        $this->assertTrue(chat_service::should_decline('', $props));
        $this->assertTrue(chat_service::should_decline('anythingelse', $props));

        // Grounded but empty propositions are refused.
        $this->assertTrue(chat_service::should_decline('strong', []));
        $this->assertTrue(chat_service::should_decline('qualified', []));
    }

    /**
     * The prompt builder bounds proposition count and total context length,
     * and produces an ordered, de-duplicated source list.
     */
    public function test_build_prompt_bounds_and_sources(): void {
        $this->resetAfterTest();
        set_config('maxpropositions', 3, 'block_cortex_chat');
        set_config('maxcontextchars', 100000, 'block_cortex_chat');

        $props = [];
        for ($i = 0; $i < 10; $i++) {
            $props[] = [
                'proposition' => 'Fact number ' . $i,
                // Two distinct references reused, to test de-duplication.
                'source_reference' => 'Source ' . ($i % 2),
            ];
        }

        $method = new \ReflectionMethod(chat_service::class, 'build_prompt');
        $method->setAccessible(true);
        [$prompt, $sources] = $method->invoke(null, 'What is the answer?', $props);

        // No more than maxpropositions numbered entries.
        $this->assertSame(1, substr_count($prompt, '[1]'));
        $this->assertSame(1, substr_count($prompt, '[3]'));
        $this->assertSame(0, substr_count($prompt, '[4]'));

        // Question and grounding instruction present.
        $this->assertStringContainsString('What is the answer?', $prompt);
        $this->assertStringContainsString('ONLY the information provided', $prompt);
        $this->assertStringContainsString('COURSE MATERIAL:', $prompt);

        // Sources are unique and ordered.
        $this->assertCount(2, $sources);
        $this->assertSame(1, $sources[0]['index']);
        $this->assertSame(2, $sources[1]['index']);
        $this->assertSame('Source 0', $sources[0]['reference']);
    }

    /**
     * The character budget caps how much context is emitted.
     */
    public function test_build_prompt_respects_char_budget(): void {
        $this->resetAfterTest();
        set_config('maxpropositions', 50, 'block_cortex_chat');
        set_config('maxcontextchars', 60, 'block_cortex_chat');

        $props = [];
        for ($i = 0; $i < 20; $i++) {
            $props[] = [
                'proposition' => str_repeat('A', 40),
                'source_reference' => 'Ref ' . $i,
            ];
        }

        $method = new \ReflectionMethod(chat_service::class, 'build_prompt');
        $method->setAccessible(true);
        [$prompt] = $method->invoke(null, 'Q?', $props);

        // With a 60-char budget and 40-char facts, only the first entry fits.
        $this->assertSame(1, substr_count($prompt, '[1]'));
        $this->assertSame(0, substr_count($prompt, '[2]'));
    }

    /**
     * Availability fails closed when the block is not configured.
     */
    public function test_availability_notconfigured(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $result = chat_service::availability($course->id, $context, 0);
        $this->assertFalse($result['available']);
        $this->assertSame('notconfigured', $result['reason']);
    }

    /**
     * Availability fails closed when the course is not enabled for Cortex.
     */
    public function test_availability_course_disabled(): void {
        $this->resetAfterTest();
        $this->configure_site();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $result = chat_service::availability($course->id, $context, 0);
        $this->assertFalse($result['available']);
        $this->assertSame('coursedisabled', $result['reason']);
    }

    /**
     * Availability fails closed when the corpus is enabled but not ready.
     */
    public function test_availability_course_not_ready(): void {
        $this->resetAfterTest();
        $this->configure_site();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        course_state::set_enabled($course->id, true); // Enabled, still idle.

        $result = chat_service::availability($course->id, $context, 0);
        $this->assertFalse($result['available']);
        $this->assertSame('coursenotready', $result['reason']);
    }

    /**
     * A fully-ready course still fails closed when no AI provider is available.
     */
    public function test_availability_requires_ai_provider(): void {
        $this->resetAfterTest();
        $this->configure_site();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $this->make_course_ready($course->id);

        // No AI provider configured in the test site.
        $result = chat_service::availability($course->id, $context, 0);
        $this->assertFalse($result['available']);
        $this->assertSame('ainotavailable', $result['reason']);
    }

    /**
     * ask() fails closed (unavailable) before any network call when not ready.
     */
    public function test_ask_unavailable_when_not_ready(): void {
        $this->resetAfterTest();
        $this->configure_site();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $result = chat_service::ask($course->id, $context, 0, 'Hello?');
        $this->assertSame(chat_service::STATUS_UNAVAILABLE, $result['status']);
        $this->assertSame('', $result['answer']);
    }

    /**
     * Live propositions render as their own section, are numbered before course
     * material, and their references appear in the merged source list.
     */
    public function test_build_prompt_merges_live_schedule(): void {
        $this->resetAfterTest();
        set_config('maxpropositions', 8, 'block_cortex_chat');
        set_config('maxcontextchars', 100000, 'block_cortex_chat');

        $cortexprops = [
            ['proposition' => 'Culture has layers.', 'source_reference' => 'Topic 1'],
        ];
        $liveprops = [
            [
                'proposition' => 'Assignment "Essay 1" - Due: Monday.',
                'source_reference' => 'Moodle live: Assignment "Essay 1"',
                'origin' => 'live',
            ],
        ];

        $method = new \ReflectionMethod(chat_service::class, 'build_prompt');
        $method->setAccessible(true);
        [$prompt, $sources] = $method->invoke(null, 'When is the essay due?', $cortexprops, $liveprops);

        // Both labelled sections are present.
        $this->assertStringContainsString('LIVE COURSE SCHEDULE:', $prompt);
        $this->assertStringContainsString('COURSE MATERIAL:', $prompt);

        // Live schedule is numbered first, course material second (continuous).
        $livepos = strpos($prompt, 'LIVE COURSE SCHEDULE:');
        $materialpos = strpos($prompt, 'COURSE MATERIAL:');
        $this->assertLessThan($materialpos, $livepos);
        $this->assertStringContainsString('[1] Assignment "Essay 1"', $prompt);
        $this->assertStringContainsString('[2] Culture has layers.', $prompt);

        // Both references are in the merged, ordered source list.
        $this->assertCount(2, $sources);
        $this->assertSame('Moodle live: Assignment "Essay 1"', $sources[0]['reference']);
        $this->assertSame('Topic 1', $sources[1]['reference']);
    }

    /**
     * With no course material and only live schedule, the prompt still renders
     * the live section and omits the empty course-material section.
     */
    public function test_build_prompt_live_only_omits_material_section(): void {
        $this->resetAfterTest();

        $liveprops = [
            [
                'proposition' => 'Quiz "Midterm" - Closes: Friday.',
                'source_reference' => 'Moodle live: Quiz "Midterm"',
                'origin' => 'live',
            ],
        ];

        $method = new \ReflectionMethod(chat_service::class, 'build_prompt');
        $method->setAccessible(true);
        [$prompt, $sources] = $method->invoke(null, 'When does the quiz close?', [], $liveprops);

        $this->assertStringContainsString('LIVE COURSE SCHEDULE:', $prompt);
        $this->assertStringNotContainsString('COURSE MATERIAL:', $prompt);
        $this->assertStringContainsString('[1] Quiz "Midterm"', $prompt);
        $this->assertCount(1, $sources);
    }
}
