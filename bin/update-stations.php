<?php
declare(strict_types=1);

/**
 * Regenerate the offline station fallback (resources/stations.tsv) from the
 * open `db-stations` dataset (DB station data, name + EVA + importance weight).
 *
 * Usage: php bin/update-stations.php [source-url]
 *
 * Output format: one "eva<TAB>weight<TAB>name" row per station, sorted by name.
 * The file is written atomically (temp + rename), so a failed run never leaves
 * a half-written fallback behind.
 */

use Bahn\Http;

require __DIR__ . '/../src/bootstrap.php';

const DEFAULT_SOURCE = 'https://cdn.jsdelivr.net/npm/db-stations@latest/full.json';

$source = $argv[1] ?? DEFAULT_SOURCE;
$target = \dirname(__DIR__) . '/resources/stations.tsv';

fwrite(STDERR, "Downloading {$source} …\n");
$body = Http::get($source, ['Accept: application/json'], 60);
if ($body === null) {
    fwrite(STDERR, "ERROR: download failed.\n");
    exit(1);
}

$data = json_decode($body, true);
if (!is_array($data)) {
    fwrite(STDERR, "ERROR: could not parse dataset as JSON.\n");
    exit(1);
}

$seen = [];
$rows = [];
foreach ($data as $s) {
    if (!is_array($s)) {
        continue;
    }
    $eva  = trim((string) ($s['id'] ?? ''));
    $name = trim((string) ($s['name'] ?? ''));
    if ($eva === '' || $name === '' || isset($seen[$eva])) {
        continue;
    }
    $seen[$eva] = true;
    $name = str_replace(["\t", "\n", "\r"], ' ', $name);
    $rows[] = [$eva, sprintf('%.1f', (float) ($s['weight'] ?? 0)), $name];
}

if ($rows === []) {
    fwrite(STDERR, "ERROR: dataset contained no usable stations — keeping existing file.\n");
    exit(1);
}

usort($rows, static fn (array $a, array $b): int => strcasecmp($a[2], $b[2]));

$dir = \dirname($target);
if (!is_dir($dir)) {
    mkdir($dir, 0o775, true);
}

$tmp = $target . '.tmp';
$fh  = fopen($tmp, 'w');
if ($fh === false) {
    fwrite(STDERR, "ERROR: cannot write {$tmp}.\n");
    exit(1);
}
foreach ($rows as [$eva, $weight, $name]) {
    fwrite($fh, "{$eva}\t{$weight}\t{$name}\n");
}
fclose($fh);
rename($tmp, $target);

printf("Wrote %d stations to %s\n", count($rows), $target);
