<?php
declare(strict_types=1);

/**
 * Tests that a "split" station (whose requested EVA has no timetable, like
 * Berlin Hbf 8011160) resolves to the main-line sibling EVA — preferring
 * non-S-Bahn trains over an S-Bahn sibling with more departures.
 *
 * Run: php tests/board_eva_resolution_test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Bahn\ConnectionFinder;
use Bahn\TimetablesSource;

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

final class SplitSource implements TimetablesSource
{
    public function resolveEva(string $pattern): ?int
    {
        return 1000;
    }

    public function station(int $eva): ?array
    {
        // Requested EVA 1000 has two siblings: 2000 (S-Bahn) and 3000 (main).
        return ['eva' => 1000, 'meta' => [2000, 3000], 'name' => 'Split Hbf'];
    }

    public function plan(int $eva, string $date, string $hour): ?\SimpleXMLElement
    {
        if ($eva === 2000) { // S-Bahn sibling — MORE stops, but all category S
            return simplexml_load_string('<timetable>'
                . '<s id="S1"><tl c="S" n="1"/><dp pt="2606231205" ppth="Wannsee"/></s>'
                . '<s id="S2"><tl c="S" n="2"/><dp pt="2606231206" ppth="Spandau"/></s>'
                . '<s id="S3"><tl c="S" n="3"/><dp pt="2606231207" ppth="Erkner"/></s>'
                . '</timetable>');
        }
        if ($eva === 3000) { // main-line sibling — fewer stops, but ICE/RE
            return simplexml_load_string('<timetable>'
                . '<s id="M1"><tl c="ICE" n="1503"/><dp pt="2606231205" ppth="München Hbf"/></s>'
                . '<s id="M2"><tl c="RE" n="5"/><dp pt="2606231210" l="RE5" ppth="Stralsund Hbf"/></s>'
                . '</timetable>');
        }
        return simplexml_load_string('<timetable/>'); // requested EVA 1000 is empty
    }

    public function fchg(int $eva): ?\SimpleXMLElement
    {
        return simplexml_load_string('<timetable/>');
    }
}

$now  = new DateTimeImmutable('2026-06-23 12:00', new DateTimeZone('Europe/Berlin'));
$data = (new ConnectionFinder(new SplitSource()))->find(1000, $now);

check('resolves to main-line EVA (not S-Bahn)', 3000, $data['eva']);
$cats = array_map(fn ($t) => $t->category, $data['departures']);
check('board shows the ICE', true, in_array('ICE', $cats, true));
check('board is not the S-Bahn one', false, in_array('S', $cats, true));

echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n{$failures} TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
