<?php
declare(strict_types=1);

namespace Bahn;

/**
 * Persistent record of connection (waiting) decisions over time.
 *
 * Each decision is keyed by the outbound/inbound train pair. On every poll the
 * current decisions are reconciled with the store: new ones are added, status
 * changes are appended to the decision's history (a "revision"), and decisions
 * that have disappeared from the feed are marked as ended ("x").
 *
 * Writes are serialized with an exclusive lock and committed atomically via a
 * temp-file rename, so the UI can read the file at any time without tearing.
 */
final class ConnectionLog
{
    /** Synthetic status used when a decision is no longer reported by DB. */
    public const STATUS_ENDED = 'x';

    private int $retentionSeconds;

    public function __construct(private string $path, int $retentionDays = 365)
    {
        $this->retentionSeconds = max(1, $retentionDays) * 24 * 3600;
    }

    /** @return array{lastPoll:?string, records:array<string,array<string,mixed>>} */
    public function load(): array
    {
        if (!is_file($this->path)) {
            return ['lastPoll' => null, 'records' => []];
        }
        $raw  = file_get_contents($this->path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return ['lastPoll' => null, 'records' => []];
        }
        return [
            'lastPoll' => $data['lastPoll'] ?? null,
            'records'  => $data['records'] ?? [],
        ];
    }

    /**
     * Reconcile the current decisions into the store.
     *
     * @param WaitingConnection[] $current
     * @return array{new:int,revised:int,ended:int,active:int}
     */
    public function record(array $current, \DateTimeImmutable $now): array
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        $fp = fopen($this->path, 'c+');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open connection log: {$this->path}");
        }

        try {
            flock($fp, LOCK_EX);
            $raw   = stream_get_contents($fp) ?: '';
            $store = json_decode($raw, true);
            if (!is_array($store)) {
                $store = ['lastPoll' => null, 'records' => []];
            }
            $records = $store['records'] ?? [];
            $ts      = $now->format(\DATE_ATOM);
            $stats   = ['new' => 0, 'revised' => 0, 'ended' => 0, 'active' => 0];

            $seen = [];
            foreach ($current as $c) {
                $key        = $this->key($c);
                $seen[$key] = true;
                $stats['active']++;

                if (!isset($records[$key])) {
                    $records[$key] = [
                        'key'       => $key,
                        'outbound'  => $this->trainInfo($c->outbound, true),
                        'inbound'   => $this->trainInfo($c->inbound, false),
                        'status'    => $c->status,
                        'firstSeen' => $ts,
                        'lastSeen'  => $ts,
                        'history'   => [['ts' => $ts, 'status' => $c->status]],
                    ];
                    $stats['new']++;
                    continue;
                }

                // Refresh live display fields and detect a status revision.
                $records[$key]['outbound'] = $this->trainInfo($c->outbound, true);
                $records[$key]['inbound']  = $this->trainInfo($c->inbound, false);
                $records[$key]['lastSeen'] = $ts;
                if ($records[$key]['status'] !== $c->status) {
                    $records[$key]['history'][] = ['ts' => $ts, 'status' => $c->status];
                    $records[$key]['status']    = $c->status;
                    $stats['revised']++;
                }
            }

            // Decisions that vanished from the feed are treated as withdrawn.
            foreach ($records as $key => $rec) {
                if (isset($seen[$key]) || ($rec['status'] ?? '') === self::STATUS_ENDED) {
                    continue;
                }
                $records[$key]['history'][] = ['ts' => $ts, 'status' => self::STATUS_ENDED];
                $records[$key]['status']    = self::STATUS_ENDED;
                $records[$key]['lastSeen']  = $ts;
                $stats['ended']++;
            }

            $records = $this->prune($records, $now);

            $store['records']  = $records;
            $store['lastPoll'] = $ts;
            $this->commit($fp, $store);

            return $stats;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** Short human label for a status code (incl. the synthetic "ended"). */
    public static function labelFor(string $code): string
    {
        return match ($code) {
            WaitingConnection::STATUS_WAITING     => 'wartet',
            WaitingConnection::STATUS_NOT_WAITING => 'wartet nicht',
            WaitingConnection::STATUS_ALTERNATIVE => 'Alternative',
            self::STATUS_ENDED                    => 'beendet',
            default                               => $code,
        };
    }

    /** CSS modifier class for a status code. */
    public static function classFor(string $code): string
    {
        return match ($code) {
            WaitingConnection::STATUS_WAITING     => 'wait',
            WaitingConnection::STATUS_NOT_WAITING => 'broken',
            WaitingConnection::STATUS_ALTERNATIVE => 'alt',
            self::STATUS_ENDED                    => 'ended',
            default                               => 'unknown',
        };
    }

    /** @param array<string,array<string,mixed>> $store */
    private function commit($fp, array $store): void
    {
        $json = json_encode(
            $store,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        );
        $tmp = $this->path . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, (string) $json);
        rename($tmp, $this->path); // atomic; readers see old or new, never partial
    }

    /**
     * @param array<string,array<string,mixed>> $records
     * @return array<string,array<string,mixed>>
     */
    private function prune(array $records, \DateTimeImmutable $now): array
    {
        $cutoff = $now->getTimestamp() - $this->retentionSeconds;
        foreach ($records as $key => $rec) {
            $last = isset($rec['lastSeen']) ? strtotime((string) $rec['lastSeen']) : false;
            if ($last !== false && $last < $cutoff) {
                unset($records[$key]);
            }
        }
        return $records;
    }

    private function key(WaitingConnection $c): string
    {
        return sprintf(
            '%s@%s>%s@%s',
            $this->trainId($c->outbound),
            $c->outbound->planned?->format('YmdHi') ?? '?',
            $this->trainId($c->inbound),
            $c->inbound->planned?->format('YmdHi') ?? '?'
        );
    }

    private function trainId(Train $t): string
    {
        $id = trim($t->category . $t->number);
        return $id !== '' ? $id : $t->name();
    }

    /** @return array<string,?string> */
    private function trainInfo(Train $t, bool $isDeparture): array
    {
        return [
            'name'     => $t->name(),
            'place'    => $isDeparture ? $t->destination() : $t->origin(),
            'planned'  => $t->planned?->format(\DATE_ATOM),
            'expected' => $t->expected?->format(\DATE_ATOM),
            'platform' => $t->platform(),
        ];
    }
}
