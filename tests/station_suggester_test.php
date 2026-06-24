<?php
declare(strict_types=1);

/**
 * Tests that StationSuggester merges StaDa + offline hits, de-dupes by EVA, and
 * ranks important stations first (via the offline weight). No network.
 *
 * Run: php tests/station_suggester_test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Bahn\StadaClient;
use Bahn\StationDirectory;
use Bahn\StationSuggester;

$failures = 0;
function check(string $label, $expected, $actual): void
{
    global $failures;
    $ok = $expected === $actual;
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s (expected %s, got %s)\n",
        $ok ? 'PASS' : 'FAIL', $label, var_export($expected, true), var_export($actual, true));
}

// Offline directory: Köln Hbf is the heaviest (most important).
$tsv = sys_get_temp_dir() . '/bahn_sugg_' . getmypid() . '.tsv';
file_put_contents($tsv, implode("\n", [
    "8000207\t900.0\tKöln Hbf",
    "8003368\t300.0\tKöln Messe/Deutz",
    "8000208\t150.0\tKöln-Ehrenfeld",
]) . "\n");
$directory = new StationDirectory($tsv);

// StaDa stub returns results in a "bad" order, plus one station not offline.
function stada_stub(string $json): StadaClient
{
    $stub = fn (string $u, array $h): ?string => $json;
    return new StadaClient('cid', 'key', sys_get_temp_dir() . '/bahn_sugg_cache_' . getmypid(), 604800, $stub);
}
$stadaJson = json_encode(['result' => [
    ['name' => 'Köln Messe/Deutz', 'category' => 2, 'mailingAddress' => ['city' => 'Köln'],
     'evaNumbers' => [['number' => 8003368, 'isMain' => true]]],
    ['name' => 'Köln Hbf', 'category' => 1, 'mailingAddress' => ['city' => 'Köln'],
     'evaNumbers' => [['number' => 8000207, 'isMain' => true]]],
    ['name' => 'Köln/Bonn Flughafen', 'category' => 3, 'mailingAddress' => ['city' => 'Köln'],
     'evaNumbers' => [['number' => 8003330, 'isMain' => true]]],
]]);

$sugg = new StationSuggester(stada_stub($stadaJson), $directory);
$res  = $sugg->suggest('köln', 10);
$names = array_column($res, 'name');

check('top station first (by weight)', 'Köln Hbf', $names[0] ?? '');
check('de-duped count (3 offline + 1 StaDa-only)', 4, count($res));
check('offline-only station kept', true, in_array('Köln-Ehrenfeld', $names, true));
check('StaDa-only station kept', true, in_array('Köln/Bonn Flughafen', $names, true));
check('StaDa city overlaid on offline entry', 'Köln', $res[0]['city'] ?? '');

// StaDa unavailable (404) -> offline-only, still ranked by weight.
$sugg404 = new StationSuggester(stada_stub('{"errNo":404,"errMsg":"Not Found"}'), $directory);
$res404  = $sugg404->suggest('koeln', 10); // ASCII umlaut -> offline matches Köln
check('offline fallback top station', 'Köln Hbf', $res404[0]['name'] ?? '');
check('offline fallback count', 3, count($res404));

@unlink($tsv);
@array_map('unlink', glob(sys_get_temp_dir() . '/bahn_sugg_cache_' . getmypid() . '/*') ?: []);
@rmdir(sys_get_temp_dir() . '/bahn_sugg_cache_' . getmypid());
echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n{$failures} TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
