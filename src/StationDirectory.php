<?php
declare(strict_types=1);

namespace Bahn;

/**
 * Offline directory of German DB stations (name + EVA + importance weight),
 * used for autocomplete suggestions and name/EVA resolution.
 *
 * The data file is a TSV with one "eva<TAB>weight<TAB>name" row per station.
 */
final class StationDirectory
{
    /** @var array<int,array{0:string,1:float,2:string}>|null */
    private ?array $rows = null;

    public function __construct(private string $path)
    {
    }

    /**
     * Stations matching $q (substring, case-insensitive), best first.
     * Prefix matches rank above mid-string matches; ties broken by station
     * importance (weight).
     *
     * @return list<array{name:string,eva:int,weight:float}>
     */
    public function search(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        $needle = $this->normalize($q);
        $scored = [];
        foreach ($this->rows() as [$eva, $weight, $name]) {
            $pos = strpos($this->normalize($name), $needle);
            if ($pos === false) {
                continue;
            }
            $score = ($pos === 0 ? 1.0e9 : 0.0) + $weight;
            $scored[] = ['name' => $name, 'eva' => (int) $eva, 'weight' => $weight, 'score' => $score];
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(
            fn (array $r): array => ['name' => $r['name'], 'eva' => $r['eva'], 'weight' => $r['weight']],
            array_slice($scored, 0, max(1, $limit))
        );
    }

    /**
     * Resolve an exact station name (case-insensitive) or an EVA number to a
     * canonical {name, eva}, or null if unknown.
     *
     * @return array{name:string,eva:int}|null
     */
    public function resolve(string $q): ?array
    {
        $q = trim($q);
        if ($q === '') {
            return null;
        }

        if (ctype_digit($q)) {
            foreach ($this->rows() as [$eva, , $name]) {
                if ($eva === $q) {
                    return ['name' => $name, 'eva' => (int) $eva];
                }
            }
            return null;
        }

        // Exact name match (case- and umlaut-insensitive); on ties prefer the
        // more important station by weight.
        $needle = $this->normalize($q);
        $best   = null;
        $bestW  = -INF;
        foreach ($this->rows() as [$eva, $weight, $name]) {
            if ($this->normalize($name) === $needle && $weight > $bestW) {
                $best  = ['name' => $name, 'eva' => (int) $eva];
                $bestW = $weight;
            }
        }
        return $best;
    }

    /** @return array<int,array{0:string,1:float,2:string}> */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }
        $this->rows = [];
        if (!is_file($this->path)) {
            return $this->rows;
        }
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $parts = explode("\t", $line, 3);
            if (count($parts) === 3) {
                $this->rows[] = [$parts[0], (float) $parts[1], $parts[2]];
            }
        }
        return $this->rows;
    }

    /**
     * Normalize for matching: lower-case and fold German umlauts to their ASCII
     * digraphs (ä→ae, ö→oe, ü→ue, ß→ss), so "Köln" matches both "köln" and
     * "koeln". strtolower handles ASCII; the umlaut bytes are mapped explicitly.
     */
    private function normalize(string $s): string
    {
        $s = strtolower($s);
        return strtr($s, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
        ]);
    }
}
