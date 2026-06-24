<?php
declare(strict_types=1);

/**
 * Single poll: fetch the current connection decisions for the station and
 * record them (with revision detection) into the connection log.
 *
 * The decision log is kept only for the configured default station (see the
 * `station:` section of config.yaml), so this poller always polls that station.
 *
 * Run once per minute (see bin/poll-loop.sh) or from cron:
 *   * * * * * php /path/to/bin/poll.php >> /path/to/data/poll.log 2>&1
 */

use function Bahn\make_finder;

require __DIR__ . '/../src/bootstrap.php';

$root    = \dirname(__DIR__);
$config  = Bahn\Config::load($root . '/config.yaml');
$station = $config->defaultStationName();

try {
    $finder = make_finder($root . '/config.yaml');
    $eva = $config->defaultEva();

    $now  = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $data = $finder->find($eva, $now);

    $log   = new Bahn\ConnectionLog($root . '/data/connection_log.json', $config->logRetentionDays());
    $stats = $log->record($data['waiting'], $now);

    printf(
        "[%s] %s: active=%d new=%d revised=%d ended=%d\n",
        $now->format('Y-m-d H:i:s'),
        $station,
        $stats['active'],
        $stats['new'],
        $stats['revised'],
        $stats['ended']
    );
} catch (Throwable $ex) {
    fwrite(STDERR, '[poll error] ' . $ex->getMessage() . "\n");
    exit(1);
}
