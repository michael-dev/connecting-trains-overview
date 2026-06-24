<?php
declare(strict_types=1);

/**
 * Offline test for the connection log: new decision, revision (status change),
 * and withdrawal (decision disappears from the feed).
 *
 * Run: php tests/connection_log_test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Bahn\ConnectionLog;
use Bahn\Train;
use Bahn\WaitingConnection;

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

$tz = new DateTimeZone('Europe/Berlin');
function train(string $cat, string $num, string $dest): Train
{
    return new Train($cat, $num, '', null, null, '', '', [$dest]);
}
function conn(string $status): WaitingConnection
{
    return new WaitingConnection(
        train('ICE', '1651', 'Dresden Hbf'),
        train('ICE', '600', 'Frankfurt(Main)Hbf'),
        $status
    );
}

$path = sys_get_temp_dir() . '/bahn_log_test_' . getmypid() . '.json';
@unlink($path);
$log = new ConnectionLog($path);

// Poll 1: a new waiting decision.
$s1 = $log->record([conn(WaitingConnection::STATUS_WAITING)], new DateTimeImmutable('2026-06-23 15:50', $tz));
check('poll1 new', 1, $s1['new']);
check('poll1 revised', 0, $s1['revised']);

// Poll 2: same decision, now "not waiting" -> a revision.
$s2 = $log->record([conn(WaitingConnection::STATUS_NOT_WAITING)], new DateTimeImmutable('2026-06-23 15:54', $tz));
check('poll2 new', 0, $s2['new']);
check('poll2 revised', 1, $s2['revised']);

// Poll 3: decision gone from the feed -> ended.
$s3 = $log->record([], new DateTimeImmutable('2026-06-23 15:58', $tz));
check('poll3 ended', 1, $s3['ended']);

$data = $log->load();
$records = array_values($data['records']);
check('one record total', 1, count($records));

$rec = $records[0];
check('current status is ended', ConnectionLog::STATUS_ENDED, $rec['status']);
check('history has 3 entries', 3, count($rec['history']));
$chain = array_map(fn ($h) => $h['status'], $rec['history']);
check('history chain w,n,x', ['w', 'n', 'x'], $chain);
check('outbound name preserved', 'ICE 1651', $rec['outbound']['name']);

@unlink($path);

// Configurable retention: a decision last seen beyond the window is pruned.
$rpath = sys_get_temp_dir() . '/bahn_log_ret_' . getmypid() . '.json';
@unlink($rpath);
$rlog = new ConnectionLog($rpath, 5); // keep 5 days
$rlog->record([conn(WaitingConnection::STATUS_WAITING)], new DateTimeImmutable('2026-06-01 12:00', $tz));
$rlog->record([], new DateTimeImmutable('2026-06-01 12:05', $tz)); // decision ends (last seen ~12:05)
$rlog->record([], new DateTimeImmutable('2026-06-20 12:00', $tz)); // 19 days later > 5-day retention
check('retention prunes records older than the window', 0, count($rlog->load()['records']));
@unlink($rpath);

echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n{$failures} TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
