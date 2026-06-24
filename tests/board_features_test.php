<?php
declare(strict_types=1);

/**
 * Tests the board features added after the bahnhof.de comparison:
 *  - cancellations (cs="c") are flagged and excluded from waiting,
 *  - the time window includes trains planned hours ago but running now,
 *  - wing trains are resolved, and
 *  - HIM disruption messages are extracted and ranked.
 *
 * Run: php tests/board_features_test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Bahn\ConnectionFinder;
use Bahn\Train;
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

final class BoardSource implements TimetablesSource
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
        if ($hour === '20') { // an early train, later delayed into "now"
            return simplexml_load_string(
                '<timetable><s id="T832"><tl c="ICE" n="832"/>'
                . '<dp pt="2606232050" pp="9" ppth="Leipzig Hbf|Berlin Hbf"/></s></timetable>');
        }
        if ($hour === '23') {
            return simplexml_load_string(
                '<timetable>'
                . '<s id="T889"><tl c="ICE" n="889"/><dp pt="2606232349" pp="2" ppth="Leipzig Hbf"/></s>'
                . '<s id="T2592"><tl c="ICE" n="2592"/>'
                . '  <dp pt="2606232314" pp="10" ppth="Leipzig Hbf|Berlin Hbf" wings="W2840"/></s>'
                . '<s id="W2840-x"><tl c="ICE" n="2840"/>'
                . '  <dp pt="2606232314" pp="10" ppth="Berlin Hbf"/></s>'
                . '</timetable>');
        }
        return simplexml_load_string('<timetable/>');
    }

    public function fchg(int $eva): ?\SimpleXMLElement
    {
        return simplexml_load_string(
            '<timetable station="Test">'
            . '<s id="T832"><m id="g1" t="h" cat="Großstörung" from="2606232200" to="2606240430" ts="2606232230" pr="1"/>'
            . '  <dp ct="2606240139"><m t="d" c="31"/><m t="d" c="38"/></dp></s>' // delayed; reasons

            . '<s id="T889"><dp ct="2606232349" cs="c"/></s>'                 // cancelled
            . '<s id="T2592"><dp ct="2606240314"/></s>'                       // delayed to 03:14
            . '<s id="Z"><m id="s1" t="h" cat="Störung" from="2606232100" to="2606240000" pr="1"/>'
            . '  <m id="i1" t="h" cat="Information" from="2606230600" to="2606232300" pr="3"/></s>'
            . '</timetable>');
    }
}

$now  = new DateTimeImmutable('2026-06-23 23:45', new DateTimeZone('Europe/Berlin'));
$data = (new ConnectionFinder(new BoardSource()))->find(8000105, $now);

$byNum = [];
foreach ($data['departures'] as $t) {
    $byNum[$t->number] = $t;
}

// Window: a train planned 20:50 but delayed to 01:39 must still be listed.
check('delayed early train present (ICE 832)', true, isset($byNum['832']));
$d832 = $byNum['832']->delayMinutes() ?? 0;
check('ICE 832 delay > 120 min', true, $d832 > 120);

// Cancellation.
check('cancelled train present (ICE 889)', true, isset($byNum['889']));
check('ICE 889 flagged cancelled', true, $byNum['889']->isCancelled() ?? false);

// Wings.
check('ICE 2592 wing resolved', ['ICE 2840'], $byNum['2592']->wings ?? null);
check('ICE 2592 fullName includes wing', 'ICE 2592 / ICE 2840', $byNum['2592']->fullName());

// Delay-cause codes translated to text (the "more info" enrichment).
check('ICE 832 reason: Bauarbeiten', true, in_array('Bauarbeiten', $byNum['832']->reasons, true));
check('ICE 832 reason: Defekt an der Strecke', true,
    in_array('Defekt an der Strecke', $byNum['832']->reasons, true));
check('MessageCatalog maps 42', 'Außerplanmäßige Geschwindigkeitsbeschränkung', Bahn\MessageCatalog::text(42));
check('MessageCatalog unknown -> null', null, Bahn\MessageCatalog::text(9999));

// Messages: 3 distinct, most severe first, with affected trains.
check('three distinct messages', 3, count($data['messages']));
check('most severe first', 'Großstörung', $data['messages'][0]['cat']);
check('Großstörung lists affected train', true,
    in_array('ICE 832', $data['messages'][0]['affected'], true));
check('Störung ranked above Information', true,
    array_search('Störung', array_column($data['messages'], 'cat'), true)
    < array_search('Information', array_column($data['messages'], 'cat'), true));

echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n{$failures} TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
