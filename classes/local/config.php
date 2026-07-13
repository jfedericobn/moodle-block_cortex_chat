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

use local_cortex\local\config as ingest_config;

/**
 * Typed accessor for block_cortex_chat site settings.
 *
 * Cortex tenant configuration is reused from local_cortex; the Service Plane
 * base URL and the chat guardrails are owned by this block.
 *
 * @package    block_cortex_chat
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var int Default maximum user question length (characters). */
    const DEFAULT_MAX_QUESTION_LENGTH = 1000;

    /** @var int Default maximum number of propositions sent to Moodle AI. */
    const DEFAULT_MAX_PROPOSITIONS = 8;

    /** @var int Default maximum total context characters sent to Moodle AI. */
    const DEFAULT_MAX_CONTEXT_CHARS = 6000;

    /** @var int Default per-user request cap within the window. */
    const DEFAULT_RATE_LIMIT = 20;

    /** @var int Default rolling rate-limit window (seconds). */
    const DEFAULT_RATE_WINDOW = 3600;

    /**
     * Cortex Service Plane base URL (no trailing slash).
     *
     * @return string
     */
    public static function service_url(): string {
        return rtrim((string)get_config('block_cortex_chat', 'serviceurl'), '/');
    }

    /**
     * Cortex tenant id, reused from local_cortex.
     *
     * @return string
     */
    public static function tenant_id(): string {
        return ingest_config::tenant_id();
    }

    /**
     * Maximum length of a single user question in characters.
     *
     * @return int
     */
    public static function max_question_length(): int {
        $value = (int)get_config('block_cortex_chat', 'maxquestionlength');
        return $value > 0 ? $value : self::DEFAULT_MAX_QUESTION_LENGTH;
    }

    /**
     * Maximum number of retrieved propositions passed to Moodle AI.
     *
     * @return int
     */
    public static function max_propositions(): int {
        $value = (int)get_config('block_cortex_chat', 'maxpropositions');
        return $value > 0 ? $value : self::DEFAULT_MAX_PROPOSITIONS;
    }

    /**
     * Maximum total characters of retrieved context passed to Moodle AI.
     *
     * @return int
     */
    public static function max_context_chars(): int {
        $value = (int)get_config('block_cortex_chat', 'maxcontextchars');
        return $value > 0 ? $value : self::DEFAULT_MAX_CONTEXT_CHARS;
    }

    /**
     * Per-user request cap within the rolling window.
     *
     * @return int
     */
    public static function rate_limit(): int {
        $value = (int)get_config('block_cortex_chat', 'ratelimit');
        return $value > 0 ? $value : self::DEFAULT_RATE_LIMIT;
    }

    /**
     * Rolling rate-limit window in seconds.
     *
     * @return int
     */
    public static function rate_window(): int {
        $value = (int)get_config('block_cortex_chat', 'ratewindow');
        return $value > 0 ? $value : self::DEFAULT_RATE_WINDOW;
    }

    /**
     * Fixed decline message shown when Cortex has no grounded answer.
     *
     * @return string
     */
    public static function decline_message(): string {
        $value = trim((string)get_config('block_cortex_chat', 'declinemessage'));
        if ($value === '') {
            $value = get_string('default_declinemessage', 'block_cortex_chat');
        }
        return $value;
    }

    /**
     * Whether the block has the minimum configuration to operate.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return self::service_url() !== '' && self::tenant_id() !== '';
    }
}
