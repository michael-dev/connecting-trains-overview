<?php
declare(strict_types=1);

/**
 * Tests the configurable tuning getters on Config: explicit values, defaults
 * when sections are absent, and sane clamping.
 *
 * Run: php tests/config_test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Bahn\Config;

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

function writeConfig(string $body): string
{
    $p = sys_get_temp_dir() . '/bahn_cfg_' . getmypid() . '_' . substr(md5($body), 0, 6) . '.yaml';
    file_put_contents($p, $body);
    return $p;
}

// 1. Explicit values are read.
$p = writeConfig(<<<YAML
backend:
  clientid: x
  apikey: y
station:
  name: "Leipzig Hbf"
  eva: 8010205
board:
  hours_back: 2
  hours_ahead: 6
  cache_ttl: 30
  refresh_seconds: 90
log:
  retention_days: 30
poll:
  interval_seconds: 120
YAML);
$c = Config::load($p);
check('station name', 'Leipzig Hbf', $c->defaultStationName());
check('station eva', 8010205, $c->defaultEva());
check('hours_back', 2, $c->boardHoursBack());
check('hours_ahead', 6, $c->boardHoursAhead());
check('cache_ttl', 30, $c->boardCacheTtl());
check('refresh_seconds', 90, $c->boardRefreshSeconds());
check('retention_days', 30, $c->logRetentionDays());
check('poll interval', 120, $c->pollIntervalSeconds());
@unlink($p);

// 2. Absent sections -> built-in defaults.
$p = writeConfig("backend:\n  clientid: x\n  apikey: y\n");
$c = Config::load($p);
check('default station name', Bahn\DEFAULT_STATION, $c->defaultStationName());
check('default hours_back', 3, $c->boardHoursBack());
check('default hours_ahead', 4, $c->boardHoursAhead());
check('default cache_ttl', 45, $c->boardCacheTtl());
check('default refresh', 60, $c->boardRefreshSeconds());
check('default retention', 365, $c->logRetentionDays());
check('default poll interval', 60, $c->pollIntervalSeconds());
@unlink($p);

// 3. Clamping of unreasonable values.
$p = writeConfig(<<<YAML
backend:
  clientid: x
  apikey: y
board:
  hours_back: -5
  refresh_seconds: 1
log:
  retention_days: 0
poll:
  interval_seconds: 1
YAML);
$c = Config::load($p);
check('hours_back clamped to 0', 0, $c->boardHoursBack());
check('refresh clamped to >=5', 5, $c->boardRefreshSeconds());
check('retention clamped to >=1', 1, $c->logRetentionDays());
check('poll clamped to >=10', 10, $c->pollIntervalSeconds());
@unlink($p);

echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n{$failures} TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
