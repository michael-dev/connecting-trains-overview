<?php
declare(strict_types=1);

namespace Bahn;

/**
 * Builds the "which outbound train waits for which inbound train" view for a
 * station by merging the planned timetable (/plan) with live changes (/fchg)
 * and reading the DB connection (<conn>) elements.
 */
final class ConnectionFinder
{
    private const TZ = 'Europe/Berlin';

    private const EVA_CACHE_TTL = 604800; // 7 days — station topology is stable

    public function __construct(private TimetablesSource $client, private ?string $evaCacheDir = null)
    {
    }

    /** Resolve a station name/pattern to its EVA number. */
    public function resolveEva(string $pattern): ?int
    {
        return $this->client->resolveEva($pattern);
    }

    /**
     * Map a requested EVA to the one IRIS actually serves board data under.
     * Some stations (e.g. Berlin Hbf) expose the timetable under a sibling EVA
     * listed in their "meta"; the requested EVA then has an empty plan. Probe
     * the requested EVA first and only consult siblings if it is empty. The
     * result is cached on disk because the mapping is effectively permanent.
     */
    private function resolveBoardEva(int $eva, \DateTimeImmutable $now): int
    {
        $cached = $this->cachedBoardEva($eva);
        if ($cached !== null) {
            return $cached;
        }

        $date = $now->format('ymd');
        $hour = $now->format('H');

        $resolved = $eva;
        $self = $this->client->plan($eva, $date, $hour);
        if ($self !== null && count($self->s) === 0) {
            // Empty here — pick the sibling EVA that looks like the main-line
            // station: prefer the most non-S-Bahn trains (so Berlin Hbf resolves
            // to its long-distance EVA, not the S-Bahn one), then most stops.
            $info = $this->client->station($eva);
            $bestScore = [-1, -1];
            foreach ($info['meta'] ?? [] as $cand) {
                if ($cand === $eva) {
                    continue;
                }
                $p = $this->client->plan($cand, $date, $hour);
                if ($p === null || count($p->s) === 0) {
                    continue;
                }
                $score = $this->boardScore($p);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $resolved  = $cand;
                }
            }
        }

        $this->storeBoardEva($eva, $resolved);
        return $resolved;
    }

    /**
     * Rank a candidate plan for "main station" likeness: [non-S-Bahn stops,
     * total stops]. Higher is better; the array compares lexicographically.
     *
     * @return array{0:int,1:int}
     */
    private function boardScore(\SimpleXMLElement $plan): array
    {
        $total = 0;
        $nonS  = 0;
        foreach ($plan->s as $s) {
            $total++;
            $cat = isset($s->tl) ? (string) $s->tl['c'] : '';
            if ($cat !== '' && $cat !== 'S') {
                $nonS++;
            }
        }
        return [$nonS, $total];
    }

    private function cachedBoardEva(int $eva): ?int
    {
        if ($this->evaCacheDir === null) {
            return null;
        }
        $path = $this->evaCacheDir . '/' . $eva . '.txt';
        if (!is_file($path) || (time() - (int) @filemtime($path)) > self::EVA_CACHE_TTL) {
            return null;
        }
        $v = (int) @file_get_contents($path);
        return $v > 0 ? $v : null;
    }

    private function storeBoardEva(int $eva, int $board): void
    {
        if ($this->evaCacheDir === null) {
            return;
        }
        if (!is_dir($this->evaCacheDir)) {
            @mkdir($this->evaCacheDir, 0o775, true);
        }
        @file_put_contents($this->evaCacheDir . '/' . $eva . '.txt', (string) $board);
    }

    /**
     * @return array{
     *   eva:int,
     *   generatedAt:\DateTimeImmutable,
     *   waiting:WaitingConnection[],
     *   departures:Train[],
     *   arrivals:Train[],
     *   messages:array<int,array{cat:string,from:?\DateTimeImmutable,to:?\DateTimeImmutable,updated:?\DateTimeImmutable,priority:int,affected:string[],affectedCount:int}>
     * }
     *
     * The window spans $hoursBack hours before to $hoursAhead hours after $now,
     * so heavily delayed trains (planned earlier, running now) still appear.
     */
    public function find(int $eva, ?\DateTimeImmutable $now = null, int $hoursBack = 3, int $hoursAhead = 4): array
    {
        $tz  = new \DateTimeZone(self::TZ);
        $now ??= new \DateTimeImmutable('now', $tz);
        $now = $now->setTimezone($tz);

        // 0. Map to the EVA IRIS serves board data under (handles split stations).
        $eva = $this->resolveBoardEva($eva, $now);

        // 1. Planned timetable across the window (past hours catch delayed trains).
        $stops = [];
        for ($i = -max(0, $hoursBack); $i <= max(0, $hoursAhead); $i++) {
            $slot = $now->modify(($i >= 0 ? '+' : '') . $i . ' hour');
            $plan = $this->client->plan($eva, $slot->format('ymd'), $slot->format('H'));
            if ($plan !== null) {
                $this->mergeStops($stops, $plan);
            }
        }

        // 2. Live changes (delays, platform changes, cancellations, connections).
        //    Only overlay onto stops already in the planned window.
        $fchg = $this->client->fchg($eva);
        if ($fchg !== null) {
            $this->mergeStops($stops, $fchg, createNew: false);
        }

        // 3. Derive the views, keyed by *expected* time so delayed trains stay.
        $cutoff     = $now->modify('-10 minutes');
        $wingIndex  = $this->buildWingIndex($stops);
        $waiting    = $this->extractWaiting($stops, $wingIndex, $tz);
        $departures = $this->board($stops, $wingIndex, 'dp', $cutoff);
        $arrivals   = $this->board($stops, $wingIndex, 'ar', $cutoff);
        $messages   = $fchg !== null ? $this->extractMessages($fchg, $stops, $tz) : [];

        return [
            'eva'         => $eva,
            'generatedAt' => $now,
            'waiting'     => $waiting,
            'departures'  => $departures,
            'arrivals'    => $arrivals,
            'messages'    => $messages,
        ];
    }

    /**
     * Distinct HIM disruption messages (t="h") across the feed, most severe first.
     * The Timetables API carries no message text, only a category and validity.
     *
     * @return array<int,array{cat:string,from:?\DateTimeImmutable,to:?\DateTimeImmutable,priority:int}>
     */
    private function extractMessages(\SimpleXMLElement $fchg, array $stops, \DateTimeZone $tz): array
    {
        $meta     = [];
        $affected = [];
        foreach ($fchg->s as $s) {
            $label = $this->stopLabel((string) $s['id'], $s, $stops);
            foreach ($this->himMessages($s) as $m) {
                $id = (string) $m['id'];
                if ($id === '') {
                    continue;
                }
                if (!isset($meta[$id])) {
                    $meta[$id] = [
                        'cat'      => (string) $m['cat'],
                        'from'     => $this->parseTime((string) $m['from'], $tz),
                        'to'       => $this->parseTime((string) $m['to'], $tz),
                        'updated'  => $this->parseTime((string) $m['ts'], $tz),
                        'priority' => (int) ($m['pr'] ?? 9),
                    ];
                }
                if ($label !== '') {
                    $affected[$id][$label] = true;
                }
            }
        }

        $out = [];
        foreach ($meta as $id => $info) {
            $trains = array_keys($affected[$id] ?? []);
            sort($trains, SORT_NATURAL);
            $info['affected']      = $trains;
            $info['affectedCount'] = count($trains);
            $out[] = $info;
        }
        usort($out, function (array $a, array $b): int {
            $sa = $this->severity($a['cat']);
            $sb = $this->severity($b['cat']);
            return $sa !== $sb ? $sa <=> $sb : $a['priority'] <=> $b['priority'];
        });
        return $out;
    }

    /**
     * Best-effort train label ("RE 1" / "ICE 832"). The trip label lives in
     * /plan, so prefer the merged $stops entry; fall back to the /fchg stop.
     *
     * @param array<string,mixed> $stops
     */
    private function stopLabel(string $id, \SimpleXMLElement $s, array $stops): string
    {
        $tl   = $stops[$id]['tl'] ?? (isset($s->tl) ? $this->attrs($s->tl) : []);
        $line = $stops[$id]['dp']['l'] ?? $stops[$id]['ar']['l'] ?? '';
        if ($line === '') {
            foreach (['dp', 'ar'] as $ev) {
                if (isset($s->$ev) && (string) $s->$ev['l'] !== '') {
                    $line = (string) $s->$ev['l'];
                    break;
                }
            }
        }
        if ($line !== '') {
            return $line; // public line label already encodes the category, e.g. "RE1"
        }
        return trim(($tl['c'] ?? '') . ' ' . ($tl['n'] ?? '')); // e.g. "ICE 832"
    }

    private function severity(string $cat): int
    {
        return ['Großstörung' => 0, 'Störung' => 1, 'Bauarbeiten' => 2, 'Information' => 3][$cat] ?? 4;
    }

    /**
     * Merge all <s> stops of a timetable document into $stops, keyed by stop id.
     * Plan data establishes the entry; change data overlays non-empty values.
     */
    private function mergeStops(array &$stops, \SimpleXMLElement $doc, bool $createNew = true): void
    {
        foreach ($doc->s as $s) {
            $id = (string) $s['id'];
            if ($id === '') {
                continue;
            }
            if (!isset($stops[$id])) {
                if (!$createNew) {
                    continue;
                }
                $stops[$id] = ['tl' => [], 'ar' => [], 'dp' => [], 'conns' => [], 'reasons' => []];
            }

            if (isset($s->tl)) {
                $stops[$id]['tl'] = $this->mergeAttrs($stops[$id]['tl'], $this->attrs($s->tl));
            }
            foreach (['ar', 'dp'] as $ev) {
                if (!isset($s->$ev)) {
                    continue;
                }
                $stops[$id][$ev] = $this->mergeAttrs($stops[$id][$ev], $this->attrs($s->$ev));
                // Delay-cause messages (t="d") hang under the ar/dp event.
                foreach ($s->$ev->m as $m) {
                    if ((string) $m['t'] === 'd' && (int) $m['c'] > 0) {
                        $stops[$id]['reasons'][(int) $m['c']] = true;
                    }
                }
            }
            foreach ($s->conn as $conn) {
                $stops[$id]['conns'][] = $conn;
            }
        }
    }

    /**
     * @param array<string,string> $wingIndex trip-key -> wing label
     * @return WaitingConnection[]
     */
    private function extractWaiting(array $stops, array $wingIndex, \DateTimeZone $tz): array
    {
        $out = [];
        foreach ($stops as $stop) {
            if ($stop['conns'] === []) {
                continue;
            }
            // The outbound train is this stop's departure.
            $outbound = $this->buildTrain($stop['tl'], $stop['dp'], $tz, $wingIndex, $stop['reasons']);
            if ($outbound->isCancelled()) {
                continue; // a cancelled train cannot wait for a connection
            }
            foreach ($stop['conns'] as $conn) {
                $cs    = (string) $conn['cs'];
                $inner = isset($conn->s) ? $conn->s : null;
                if ($inner === null) {
                    continue;
                }
                // The inbound train is the connecting trip's arrival.
                $innerTl = isset($inner->tl) ? $this->attrs($inner->tl) : [];
                $innerAr = isset($inner->ar) ? $this->attrs($inner->ar) : [];
                $innerDp = isset($inner->dp) ? $this->attrs($inner->dp) : [];
                $event   = $innerAr !== [] ? $innerAr : $innerDp;
                $inbound = $this->buildTrain($innerTl, $event, $tz);

                $out[] = new WaitingConnection($outbound, $inbound, $cs !== '' ? $cs : 'w');
            }
        }

        // Held connections first, then alternatives, then broken; each by time.
        usort($out, function (WaitingConnection $a, WaitingConnection $b): int {
            if ($a->sortPriority() !== $b->sortPriority()) {
                return $a->sortPriority() <=> $b->sortPriority();
            }
            $ta = $a->outbound->time();
            $tb = $b->outbound->time();
            return ($ta?->getTimestamp() ?? 0) <=> ($tb?->getTimestamp() ?? 0);
        });

        return $out;
    }

    /**
     * Build a time-sorted board of trains for the given event type ('ar'|'dp'),
     * dropping any whose time is before $cutoff.
     *
     * @param array<string,string> $wingIndex trip-key -> wing label
     * @return Train[]
     */
    private function board(array $stops, array $wingIndex, string $event, \DateTimeImmutable $cutoff): array
    {
        $tz = $cutoff->getTimezone();
        $trains = [];
        foreach ($stops as $stop) {
            if ($stop[$event] === []) {
                continue;
            }
            $train = $this->buildTrain($stop['tl'], $stop[$event], $tz, $wingIndex, $stop['reasons']);
            $t = $train->time();
            if ($t !== null && $t < $cutoff) {
                continue;
            }
            $trains[] = $train;
        }
        usort($trains, fn (Train $a, Train $b): int =>
            ($a->time()?->getTimestamp() ?? 0) <=> ($b->time()?->getTimestamp() ?? 0));
        return $trains;
    }

    /**
     * @param array<string,string> $tl
     * @param array<string,string> $ev
     * @param array<string,string> $wingIndex   trip-key -> wing label
     * @param array<int,bool>      $reasonCodes delay-cause codes for this stop
     */
    private function buildTrain(array $tl, array $ev, \DateTimeZone $tz, array $wingIndex = [], array $reasonCodes = []): Train
    {
        $pathStr = ($ev['cpth'] ?? '') !== '' ? $ev['cpth'] : ($ev['ppth'] ?? '');
        $path    = $pathStr === '' ? [] : array_values(array_filter(explode('|', $pathStr)));

        $reasons = [];
        foreach (array_keys($reasonCodes) as $code) {
            $text = MessageCatalog::text((int) $code);
            if ($text !== null) {
                $reasons[] = $text;
            }
        }

        return new Train(
            category: $tl['c'] ?? '',
            number: $tl['n'] ?? '',
            line: $ev['l'] ?? '',
            planned: $this->parseTime($ev['pt'] ?? '', $tz),
            expected: $this->parseTime($ev['ct'] ?? '', $tz),
            plannedPlatform: $ev['pp'] ?? '',
            changedPlatform: $ev['cp'] ?? '',
            path: $path,
            cancelled: ($ev['cs'] ?? '') === 'c',
            wings: $this->resolveWings($ev['wings'] ?? '', $wingIndex),
            reasons: $reasons
        );
    }

    /**
     * Index stops by their trip key (the stop id without the trailing
     * "-<index>" segment, which is what a `wings` attribute references) so wing
     * resolution is an O(1) lookup instead of scanning every stop.
     *
     * @param array<string,mixed> $stops
     * @return array<string,string> trip-key -> wing label
     */
    private function buildWingIndex(array $stops): array
    {
        $index = [];
        foreach ($stops as $sid => $stop) {
            $pos  = strrpos($sid, '-');
            $key  = $pos === false ? $sid : substr($sid, 0, $pos);
            $name = trim(($stop['tl']['c'] ?? '') . ' ' . ($stop['tl']['n'] ?? ''));
            if ($key !== '' && $name !== '') {
                $index[$key] = $name;
            }
        }
        return $index;
    }

    /**
     * Resolve a "wings" attribute (pipe-separated trip ids) to coupled-train
     * labels via the precomputed wing index.
     *
     * @param array<string,string> $wingIndex
     * @return string[]
     */
    private function resolveWings(string $wings, array $wingIndex): array
    {
        if ($wings === '') {
            return [];
        }
        $names = [];
        foreach (array_filter(explode('|', $wings)) as $wid) {
            if (isset($wingIndex[$wid])) {
                $names[] = $wingIndex[$wid];
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * HIM messages (t="h") attached to a stop — collected from the stop itself
     * and its ar/dp events without an xpath compile per stop.
     *
     * @return \SimpleXMLElement[]
     */
    private function himMessages(\SimpleXMLElement $s): array
    {
        $containers = [$s];
        if (isset($s->ar)) {
            $containers[] = $s->ar;
        }
        if (isset($s->dp)) {
            $containers[] = $s->dp;
        }
        $out = [];
        foreach ($containers as $c) {
            foreach ($c->m as $m) {
                if ((string) $m['t'] === 'h') {
                    $out[] = $m;
                }
            }
        }
        return $out;
    }

    /** Parse a DB timestamp (YYMMDDHHmm) into a DateTimeImmutable. */
    private function parseTime(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('ymdHi', $value, $tz);
        return $dt === false ? null : $dt;
    }

    /** @return array<string,string> */
    private function attrs(\SimpleXMLElement $el): array
    {
        $out = [];
        foreach ($el->attributes() as $name => $value) {
            $out[(string) $name] = (string) $value;
        }
        return $out;
    }

    /**
     * @param array<string,string> $base
     * @param array<string,string> $overlay
     * @return array<string,string>
     */
    private function mergeAttrs(array $base, array $overlay): array
    {
        foreach ($overlay as $k => $v) {
            if ($v !== '') {
                $base[$k] = $v;
            }
        }
        return $base;
    }
}
