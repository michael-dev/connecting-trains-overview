<?php
declare(strict_types=1);

/**
 * Offline test for the connection-extraction logic, using a fake timetable
 * source so it does not depend on the live API or on active delays.
 *
 * Run: php tests/waiting_connection_test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Bahn\ConnectionFinder;
use Bahn\TimetablesSource;
use Bahn\WaitingConnection;

/** A canned source: an ICE to Dresden that waits for an ICE from Frankfurt. */
final class FakeSource implements TimetablesSource
{
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
        return simplexml_load_string(
            '<timetable><s id="OUT-1"><tl f="F" t="p" o="80" c="ICE" n="1651"/>'
            . '<dp pt="2606231549" pp="2" ppth="Leipzig Hbf|Dresden Hbf"/></s></timetable>'
        );
    }

    public function fchg(int $eva): ?\SimpleXMLElement
    {
        // One stop with three connection decisions: waiting (w), alternative (a)
        // and broken (n) — to verify all three statuses are surfaced.
        return simplexml_load_string(
            '<timetable station="Frankfurt (Main) Hbf"><s id="OUT-1" eva="8000105">'
            . '<dp ct="2606231605" cp="5"/>'
            . '<conn id="c1" cs="w" ts="2606231600">'
            . '  <s id="IN-9" eva="8000105"><tl c="ICE" n="600"/>'
            . '    <ar pt="2606231541" ct="2606231603" pp="2" ppth="Frankfurt(Main)Hbf|Fulda|Eisenach"/></s>'
            . '</conn>'
            . '<conn id="c2" cs="a" ts="2606231600">'
            . '  <s id="IN-7" eva="8000105"><tl c="RE" n="1"/>'
            . '    <ar pt="2606231548" pp="3" ppth="Kassel|Gotha"/></s>'
            . '</conn>'
            . '<conn id="c3" cs="n" ts="2606231600">'
            . '  <s id="IN-3" eva="8000105"><tl c="RB" n="20"/>'
            . '    <ar pt="2606231535" ct="2606231620" pp="4" ppth="Hanau Hbf|Offenbach"/></s>'
            . '</conn>'
            . '</s></timetable>'
        );
    }
}

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

$finder = new ConnectionFinder(new FakeSource());
$now = new DateTimeImmutable('2026-06-23 15:45', new DateTimeZone('Europe/Berlin'));
$data = $finder->find(8000105, $now);

check('three connection decisions found', 3, count($data['waiting']));

// Ordering: waiting (w) first, then alternative (a), then broken (n).
$classes = array_map(fn (WaitingConnection $c) => $c->statusClass(), $data['waiting']);
check('ordered wait, alt, broken', ['wait', 'alt', 'broken'], $classes);

/** @var WaitingConnection $w */
$w = $data['waiting'][0];
check('w: status is waiting', true, $w->isWaiting());
check('w: label', 'wartet', $w->statusLabel());
check('w: relation', 'wartet auf', $w->relationLabel());
check('w: outbound is ICE 1651', 'ICE 1651', $w->outbound->name());
check('w: outbound destination', 'Dresden Hbf', $w->outbound->destination());
check('w: outbound delay (16 min)', 16, $w->outbound->delayMinutes());
check('w: inbound is ICE 600', 'ICE 600', $w->inbound->name());
check('w: inbound origin', 'Frankfurt(Main)Hbf', $w->inbound->origin());
check('w: inbound delay (22 min)', 22, $w->inbound->delayMinutes());
check('w: outbound track switched', true, $w->outbound->platformChanged());
check('w: outbound planned track', '2', $w->outbound->plannedPlatform);
check('w: outbound current track', '5', $w->outbound->platform());
check('w: inbound track not switched', false, $w->inbound->platformChanged());

/** @var WaitingConnection $a */
$a = $data['waiting'][1];
check('a: not counted as waiting', false, $a->isWaiting());
check('a: label', 'Alternative', $a->statusLabel());
check('a: relation', 'Alternative zu', $a->relationLabel());
check('a: inbound is RE 1', 'RE 1', $a->inbound->name());

/** @var WaitingConnection $n */
$n = $data['waiting'][2];
check('n: not counted as waiting', false, $n->isWaiting());
check('n: label', 'wartet nicht', $n->statusLabel());
check('n: relation', 'wartet nicht (mehr) auf', $n->relationLabel());
check('n: icon', '✕', $n->statusIcon());
check('n: inbound is RB 20', 'RB 20', $n->inbound->name());

echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n{$failures} TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
