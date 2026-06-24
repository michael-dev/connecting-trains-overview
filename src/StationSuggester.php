<?php
declare(strict_types=1);

namespace Bahn;

/**
 * Produces station autocomplete suggestions by merging the live StaDa search
 * with the offline directory, de-duplicated by EVA. The offline directory
 * carries traffic-based importance weights, so merging guarantees that top
 * stations rank first even when StaDa's own ordering would bury them — and it
 * keeps working (umlauts, outages) when StaDa returns nothing.
 */
final class StationSuggester
{
    public function __construct(
        private StadaClient $stada,
        private StationDirectory $directory
    ) {
    }

    /** @return list<array{name:string,eva:int,city:string}> */
    public function suggest(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        // Pull a generous slice from both sources, then merge + re-rank.
        $stada   = $this->stada->search($q, 25);
        $offline = $this->directory->search($q, 50);

        /** @var array<int,array{name:string,eva:int,city:string,weight:float}> $byEva */
        $byEva = [];

        // Offline first — establishes the importance weight per station.
        foreach ($offline as $o) {
            if ($o['eva'] <= 0) {
                continue;
            }
            $byEva[$o['eva']] = [
                'name'   => $o['name'],
                'eva'    => $o['eva'],
                'city'   => '',
                'weight' => $o['weight'],
            ];
        }

        // StaDa overlays the authoritative name + city; for stations not in the
        // offline set, derive a weight from the station category (1..7).
        foreach ($stada as $s) {
            if ($s['eva'] <= 0) {
                continue;
            }
            if (isset($byEva[$s['eva']])) {
                $byEva[$s['eva']]['name'] = $s['name'];
                $byEva[$s['eva']]['city'] = $s['city'];
            } else {
                $byEva[$s['eva']] = [
                    'name'   => $s['name'],
                    'eva'    => $s['eva'],
                    'city'   => $s['city'],
                    'weight' => $this->weightForCategory($s['category']),
                ];
            }
        }

        $candidates = array_values($byEva);
        $needle = $this->normalize($q);
        usort($candidates, function (array $a, array $b) use ($needle): int {
            $pa = str_starts_with($this->normalize($a['name']), $needle) ? 0 : 1;
            $pb = str_starts_with($this->normalize($b['name']), $needle) ? 0 : 1;
            if ($pa !== $pb) {
                return $pa <=> $pb;                  // prefix matches first
            }
            if ($a['weight'] !== $b['weight']) {
                return $b['weight'] <=> $a['weight']; // important stations first
            }
            if (strlen($a['name']) !== strlen($b['name'])) {
                return strlen($a['name']) <=> strlen($b['name']);
            }
            return strcmp($a['name'], $b['name']);
        });

        return array_map(
            fn (array $r): array => ['name' => $r['name'], 'eva' => $r['eva'], 'city' => $r['city']],
            array_slice($candidates, 0, max(1, $limit))
        );
    }

    /** Map a StaDa category (1 = major hub … 7) to a comparable weight. */
    private function weightForCategory(int $category): float
    {
        $category = max(1, min(7, $category));
        return (8 - $category) * 100.0; // cat 1 -> 700, cat 7 -> 100
    }

    private function normalize(string $s): string
    {
        $s = strtolower($s);
        return strtr($s, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
        ]);
    }
}
