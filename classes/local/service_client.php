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
 * Thin HTTP client for the Cortex Service Plane.
 *
 * Only the server-side code calls this; the Service URL, tenant id, and
 * reference code never reach the browser.
 *
 * @package    block_cortex_chat
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_client {
    /** @var int Connect timeout (seconds). */
    const CONNECT_TIMEOUT = 5;

    /** @var int Request timeout (seconds) for retrieve. */
    const RETRIEVE_TIMEOUT = 20;

    /** @var self|null Overridable instance, used by tests to stub the network. */
    private static ?self $instance = null;

    /**
     * Factory. Returns the injected instance if one was set (tests), else a new client.
     *
     * @return self
     */
    public static function instance(): self {
        return self::$instance ?? new self();
    }

    /**
     * Override the instance returned by {@see instance()}. Test-only.
     *
     * @param self|null $instance
     */
    public static function set_instance(?self $instance): void {
        self::$instance = $instance;
    }

    /**
     * Retrieve grounded context for a course-scoped question.
     *
     * @param string $referencecode The ready course reference code.
     * @param string $query The user question.
     * @return array{httpcode:int,body:array,raw:string}
     */
    public function retrieve(string $referencecode, string $query): array {
        $url = config::service_url() . '/api/v1/service/retrieve';
        $payload = [
            'tenant_id' => config::tenant_id(),
            'reference_code' => $referencecode,
            'query' => $query,
        ];
        $curl = $this->new_curl(self::RETRIEVE_TIMEOUT);
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $raw = $curl->post($url, $json);
        return $this->wrap_response($curl, $raw);
    }

    /**
     * Build a configured Moodle curl instance.
     *
     * @param int $timeout Request timeout in seconds.
     * @return \curl
     */
    private function new_curl(int $timeout): \curl {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_FOLLOWLOCATION' => 0,
        ]);
        return $curl;
    }

    /**
     * Normalise a curl response into a structured array.
     *
     * @param \curl $curl
     * @param string|bool $raw
     * @return array{httpcode:int,body:array,raw:string}
     */
    private function wrap_response(\curl $curl, $raw): array {
        $raw = is_string($raw) ? $raw : '';
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        $decoded = json_decode($raw, true);
        return [
            'httpcode' => $code,
            'body' => is_array($decoded) ? $decoded : [],
            'raw' => $raw,
        ];
    }
}
