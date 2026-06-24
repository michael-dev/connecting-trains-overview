<?php
declare(strict_types=1);

/**
 * Shared bootstrap: register a simple autoloader for the Bahn\ namespace and
 * provide a factory that wires config -> client -> finder.
 */

namespace Bahn;

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Bahn\\')) {
        return;
    }
    $file = __DIR__ . '/' . substr($class, strlen('Bahn\\')) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Built-in fallback for the default/reference station, used when config.yaml
 * has no `station:` section. Override it there (see config.yaml.example).
 * The default station is also the only one the decision log is kept for.
 */
const DEFAULT_STATION = 'Frankfurt (Main) Hbf';
const DEFAULT_EVA = 8000105;

/** Build a ConnectionFinder from config.yaml in the project root. */
function make_finder(string $configPath): ConnectionFinder
{
    $config = Config::load($configPath);
    $client = new TimetablesClient($config->clientId(), $config->apiKey());
    return new ConnectionFinder($client, \dirname($configPath) . '/data/cache/eva');
}

/** Build a cached StaDa station-search client (cache under <root>/data/cache). */
function make_stada(string $configPath): StadaClient
{
    $config = Config::load($configPath);
    return new StadaClient(
        $config->clientId(),
        $config->apiKey(),
        \dirname($configPath) . '/data/cache/stations'
    );
}

/**
 * Run ConnectionFinder::find() with a short-lived file cache, so the
 * auto-refreshing web page does not re-issue ~9 API calls on every load.
 * The poller deliberately bypasses this (calls find() directly) to stay live.
 *
 * @return array<string,mixed>
 */
function find_cached(ConnectionFinder $finder, int $eva, string $cacheDir, int $ttl = 45): array
{
    $path = $cacheDir . '/board_' . $eva . '.cache';
    if (is_file($path) && (time() - (int) @filemtime($path)) < $ttl) {
        $blob = @file_get_contents($path);
        if ($blob !== false) {
            $data = @unserialize($blob);
            if (is_array($data)) {
                return $data;
            }
        }
    }

    $data = $finder->find($eva);

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0o775, true);
    }
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, serialize($data)) !== false) {
        @rename($tmp, $path);
    }
    return $data;
}

/** Build the station suggester (live StaDa merged with the offline directory). */
function make_suggester(string $configPath): StationSuggester
{
    $root = \dirname($configPath);
    return new StationSuggester(
        make_stada($configPath),
        new StationDirectory($root . '/resources/stations.tsv')
    );
}
