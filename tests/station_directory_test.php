<?php
declare(strict_types=1);

/**
 * Tests for the offline station directory: search ranking, umlaut handling
 * (köln / koeln), and name/EVA resolution.
 *
 * Run: php tests/station_directory_test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Bahn\StationDirectory;

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

$tsv = __DIR__ . '/../resources/stations.tsv';
if (!is_file($tsv)) {
    echo "SKIP: resources/stations.tsv missing — run `php bin/update-stations.php` first.\n";
    exit(0);
}
$dir = new StationDirectory($tsv);

$top = fn (string $q): string => ($dir->search($q, 5)[0]['name'] ?? '');

check('search "münch" top is München Hbf', 'München Hbf', $top('münch'));
check('search "leip" top is Leipzig Hbf', 'Leipzig Hbf', $top('leip'));
check('search "frankf" top is Frankfurt (Main) Hbf', 'Frankfurt (Main) Hbf', $top('frankf'));

// Umlaut: both spellings should find Köln Hbf.
check('search "köln" top is Köln Hbf', 'Köln Hbf', $top('köln'));
check('search "koeln" top is Köln Hbf (ASCII)', 'Köln Hbf', $top('koeln'));

check('search limit respected', 3, count($dir->search('berlin', 3)));
check('search blank is empty', [], $dir->search('  '));

check('resolve exact name', ['name' => 'Frankfurt (Main) Hbf', 'eva' => 8000105], $dir->resolve('frankfurt (main) hbf'));
check('resolve ASCII umlaut name', 8000207, $dir->resolve('koeln hbf')['eva'] ?? null);
check('resolve by EVA', 'Berlin Hauptbahnhof', $dir->resolve('8011160')['name'] ?? null);
check('resolve unknown is null', null, $dir->resolve('Nirgendwo 123'));

echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n{$failures} TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
