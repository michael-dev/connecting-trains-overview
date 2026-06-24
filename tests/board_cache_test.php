<?php
declare(strict_types=1);

/**
 * Tests the short-TTL board cache (find_cached): a repeated call within the TTL
 * is served from disk without re-hitting the API; an expired entry refetches.
 *
 * Run: php tests/board_cache_test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Bahn\ConnectionFinder;
use Bahn\TimetablesSource;
use function Bahn\find_cached;

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

final class CountingSource implements TimetablesSource
{
    public int $planCalls = 0;
    public int $fchgCalls = 0;

    public function resolveEva(string $pattern): ?int
    {
        return 8000105;
    }

    public function station(int $eva): ?array
    {
        return null;
    }

    public function plan(int $eva, string $date, string $hour): ?\SimpleXMLElement
    {
        $this->planCalls++;
        return simplexml_load_string('<timetable><s id="A"><tl c="ICE" n="1"/>'
            . '<dp pt="2606231200" pp="1" ppth="Leipzig Hbf"/></s></timetable>');
    }

    public function fchg(int $eva): ?\SimpleXMLElement
    {
        $this->fchgCalls++;
        return simplexml_load_string('<timetable/>');
    }
}

$src    = new CountingSource();
$finder = new ConnectionFinder($src);
$dir    = sys_get_temp_dir() . '/bahn_board_cache_' . getmypid();
@array_map('unlink', glob($dir . '/*') ?: []);

$a = find_cached($finder, 8000105, $dir, 45);
$afterFirst = $src->planCalls;
check('first call hit the API', true, $afterFirst > 0);

$b = find_cached($finder, 8000105, $dir, 45);
check('second call served from cache (no new API calls)', $afterFirst, $src->planCalls);
check('cached result equals live result', count($a['departures']), count($b['departures']));
check('cached generatedAt survives unserialize', true, $b['generatedAt'] instanceof DateTimeImmutable);

// TTL 0 forces a refetch.
find_cached($finder, 8000105, $dir, 0);
check('expired entry refetches', true, $src->planCalls > $afterFirst);

@array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n{$failures} TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
