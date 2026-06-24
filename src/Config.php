<?php
declare(strict_types=1);

namespace Bahn;

/**
 * Loads the DB API credentials from config.yaml.
 *
 * Uses the native ext-yaml parser when available, otherwise falls back to a
 * minimal indentation-based parser that is sufficient for the simple two-level
 * structure of config.yaml (no lists, no inline maps).
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Config file not found or unreadable: {$path}");
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException("Could not read config file: {$path}");
        }

        if (function_exists('yaml_parse')) {
            $parsed = @yaml_parse($text);
            if (is_array($parsed)) {
                return new self($parsed);
            }
        }

        return new self(self::parseSimpleYaml($text));
    }

    public function clientId(): string
    {
        $v = $this->data['backend']['clientid'] ?? null;
        if (!is_string($v) || $v === '') {
            throw new \RuntimeException('Missing backend.clientid in config.yaml');
        }
        return $v;
    }

    public function apiKey(): string
    {
        $v = $this->data['backend']['apikey'] ?? null;
        if (!is_string($v) || $v === '') {
            throw new \RuntimeException('Missing backend.apikey in config.yaml');
        }
        return $v;
    }

    /** Default/reference station name (config `station.name`, else built-in default). */
    public function defaultStationName(): string
    {
        $v = $this->data['station']['name'] ?? null;
        return is_string($v) && trim($v) !== '' ? trim($v) : DEFAULT_STATION;
    }

    /** Default/reference station EVA (config `station.eva`, else built-in default). */
    public function defaultEva(): int
    {
        $v = $this->data['station']['eva'] ?? null;
        return is_numeric($v) ? (int) $v : DEFAULT_EVA;
    }

    /** Hours of timetable to fetch before/after now (config `board.hours_back|hours_ahead`). */
    public function boardHoursBack(): int
    {
        return max(0, $this->intValue('board', 'hours_back', 3));
    }

    public function boardHoursAhead(): int
    {
        return max(0, $this->intValue('board', 'hours_ahead', 4));
    }

    /** Seconds the board snapshot is cached server-side (config `board.cache_ttl`). */
    public function boardCacheTtl(): int
    {
        return max(0, $this->intValue('board', 'cache_ttl', 45));
    }

    /** Web UI auto-refresh interval in seconds (config `board.refresh_seconds`). */
    public function boardRefreshSeconds(): int
    {
        return max(5, $this->intValue('board', 'refresh_seconds', 60));
    }

    /** Days to keep decision-log records (config `log.retention_days`). */
    public function logRetentionDays(): int
    {
        return max(1, $this->intValue('log', 'retention_days', 365));
    }

    /** Poller interval in seconds (config `poll.interval_seconds`). */
    public function pollIntervalSeconds(): int
    {
        return max(10, $this->intValue('poll', 'interval_seconds', 60));
    }

    private function intValue(string $section, string $key, int $default): int
    {
        $v = $this->data[$section][$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    /**
     * Minimal YAML reader for a one-level-nested mapping of scalars.
     * Good enough for config.yaml; not a general YAML implementation.
     *
     * @return array<string,mixed>
     */
    private static function parseSimpleYaml(string $text): array
    {
        $result = [];
        $current = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            // Top-level "key:" or "key: value"
            if (preg_match('/^(\S[^:]*):\s*(.*)$/', $line, $m)) {
                $key = trim($m[1]);
                $val = self::unquote(trim($m[2]));
                if ($val === '') {
                    $result[$key] = [];
                    $current = $key;
                } else {
                    $result[$key] = $val;
                    $current = null;
                }
                continue;
            }
            // Indented "  key: value" belonging to the current top-level key
            if ($current !== null && preg_match('/^\s+(\S[^:]*):\s*(.*)$/', $line, $m)) {
                $result[$current][trim($m[1])] = self::unquote(trim($m[2]));
            }
        }
        return $result;
    }

    private static function unquote(string $v): string
    {
        $len = strlen($v);
        if ($len >= 2) {
            $first = $v[0];
            $last = $v[$len - 1];
            if (($first === '"' || $first === "'") && $first === $last) {
                return substr($v, 1, -1);
            }
        }
        return $v;
    }
}
