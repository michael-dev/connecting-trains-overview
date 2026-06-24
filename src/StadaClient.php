<?php
declare(strict_types=1);

namespace Bahn;

/**
 * Station search backed by the DB "StaDa – Station Data" API, with a local
 * file cache to spare the rate limit. Results are cached per normalized query;
 * cached entries are reused for `ttl` seconds, and a stale entry is still used
 * if the API call later fails.
 */
final class StadaClient
{
    private const BASE = 'https://apis.deutschebahn.com/db-api-marketplace/apis/station-data/v2';

    /** Cap on cached query files; oldest are evicted beyond this. */
    private const MAX_CACHE_FILES = 2000;

    /** @var callable(string,array):?string */
    private $httpGet;

    /**
     * @param callable(string,array):?string|null $httpGet Override for testing.
     */
    public function __construct(
        private string $clientId,
        private string $apiKey,
        private string $cacheDir,
        private int $ttl = 604800, // 7 days; station master data changes rarely
        ?callable $httpGet = null
    ) {
        $this->httpGet = $httpGet ?? static fn (string $u, array $h): ?string => Http::get($u, $h);
    }

    /**
     * Stations matching $q (substring), ranked best-first.
     * Returns [] both when nothing matches and when the API is unavailable with
     * no cache — callers can then fall back to the offline directory.
     *
     * @return list<array{name:string,eva:int,city:string,category:int}>
     */
    public function search(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        // Cache key mirrors the actual API query (case-insensitive), NOT the
        // umlaut-folded form — "köln" and "koeln" are different API queries.
        $key = $this->cacheKey($q);

        $cached = $this->cacheGet($key, false);
        if ($cached !== null) {
            return $this->rank($cached, $q, $limit);
        }

        $fetched = $this->fetch($q);
        if ($fetched === null) {
            // API failed — fall back to a stale cache entry if we have one.
            $stale = $this->cacheGet($key, true);
            return $stale === null ? [] : $this->rank($stale, $q, $limit);
        }

        $this->cachePut($key, $fetched);
        return $this->rank($fetched, $q, $limit);
    }

    /** @return list<array{name:string,eva:int,city:string,category:int}>|null */
    private function fetch(string $q): ?array
    {
        $url = self::BASE . '/stations?limit=100&searchstring=' . rawurlencode('*' . $q . '*');
        $body = ($this->httpGet)($url, [
            'DB-Client-Id: ' . $this->clientId,
            'DB-Api-Key: ' . $this->apiKey,
            'Accept: application/json',
        ]);
        if ($body === null) {
            return null; // transport failure — do not cache
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        // StaDa answers a no-match query with HTTP 404 {"errNo":404,...}.
        // Treat that as a valid empty result so it can be cached (negative cache).
        if ((int) ($data['errNo'] ?? 0) === 404) {
            return [];
        }
        if (!isset($data['result']) || !is_array($data['result'])) {
            return null; // auth error / unexpected shape — don't cache
        }

        $items = [];
        foreach ($data['result'] as $s) {
            $name = trim((string) ($s['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $items[] = [
                'name'     => $name,
                'eva'      => $this->mainEva($s),
                'city'     => trim((string) ($s['mailingAddress']['city'] ?? '')),
                'category' => (int) ($s['category'] ?? 7),
            ];
        }
        return $items;
    }

    /** @param array<string,mixed> $s */
    private function mainEva(array $s): int
    {
        $evas = $s['evaNumbers'] ?? [];
        if (is_array($evas)) {
            foreach ($evas as $e) {
                if (($e['isMain'] ?? false) === true) {
                    return (int) ($e['number'] ?? 0);
                }
            }
            if (isset($evas[0]['number'])) {
                return (int) $evas[0]['number'];
            }
        }
        return 0;
    }

    /**
     * @param list<array{name:string,eva:int,city:string,category:int}> $items
     * @return list<array{name:string,eva:int,city:string,category:int}>
     */
    private function rank(array $items, string $q, int $limit): array
    {
        $nq = $this->normalize($q);
        usort($items, function (array $a, array $b) use ($nq): int {
            $pa = str_starts_with($this->normalize($a['name']), $nq) ? 0 : 1;
            $pb = str_starts_with($this->normalize($b['name']), $nq) ? 0 : 1;
            if ($pa !== $pb) {
                return $pa <=> $pb;            // prefix matches first
            }
            if ($a['category'] !== $b['category']) {
                return $a['category'] <=> $b['category']; // bigger hubs first (cat 1..7)
            }
            if (strlen($a['name']) !== strlen($b['name'])) {
                return strlen($a['name']) <=> strlen($b['name']); // shorter ≈ main station
            }
            return strcmp($a['name'], $b['name']);
        });
        return array_slice($items, 0, max(1, $limit));
    }

    /** @return list<array{name:string,eva:int,city:string,category:int}>|null */
    private function cacheGet(string $key, bool $allowStale): ?array
    {
        $path = $this->cachePath($key);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['items'])) {
            return null;
        }
        if (!$allowStale && (time() - (int) ($data['ts'] ?? 0)) > $this->ttl) {
            return null;
        }
        return $data['items'];
    }

    /** @param list<array{name:string,eva:int,city:string,category:int}> $items */
    private function cachePut(string $key, array $items): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o775, true);
        }
        $path = $this->cachePath($key);
        $tmp  = $path . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, (string) json_encode(['ts' => time(), 'items' => $items], JSON_UNESCAPED_UNICODE));
        @rename($tmp, $path);
        $this->evict();
    }

    /** Keep the cache directory bounded by removing the oldest entries. */
    private function evict(): void
    {
        $files  = glob($this->cacheDir . '/*.json') ?: [];
        $excess = count($files) - self::MAX_CACHE_FILES;
        if ($excess <= 0) {
            return;
        }
        usort($files, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));
        foreach (array_slice($files, 0, $excess) as $old) {
            @unlink($old);
        }
    }

    private function cachePath(string $key): string
    {
        return $this->cacheDir . '/' . sha1($key) . '.json';
    }

    /** Case-insensitive cache key that preserves umlauts (mirrors the API query). */
    private function cacheKey(string $q): string
    {
        return strtolower(trim($q));
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
