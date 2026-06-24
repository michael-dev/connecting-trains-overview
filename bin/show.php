<?php
declare(strict_types=1);

/**
 * CLI: print which outbound trains are waiting for which inbound trains.
 *
 * Usage: php bin/show.php ["Station name"]
 */

use function Bahn\make_finder;

require __DIR__ . '/../src/bootstrap.php';

$config  = Bahn\Config::load(__DIR__ . '/../config.yaml');
$station = $argv[1] ?? $config->defaultStationName();

try {
    $finder = make_finder(__DIR__ . '/../config.yaml');
    $eva = $finder->resolveEva($station)
        ?? ($station === $config->defaultStationName() ? $config->defaultEva() : null);
    if ($eva === null) {
        fwrite(STDERR, "Could not resolve station: {$station}\n");
        exit(1);
    }
    $data = $finder->find($eva);
} catch (Throwable $ex) {
    fwrite(STDERR, 'Error: ' . $ex->getMessage() . "\n");
    exit(1);
}

$fmt = static fn (?DateTimeImmutable $t): string => $t ? $t->format('H:i') : '--:--';
$delay = static function (Bahn\Train $t): string {
    $d = $t->delayMinutes();
    return $d !== null && $d > 0 ? "+{$d}" : 'pünktl.';
};
$track = static function (Bahn\Train $t): string {
    if ($t->platformChanged()) {
        return $t->plannedPlatform . '→' . $t->platform();
    }
    return $t->platform() !== '' ? $t->platform() : '-';
};

printf("Anschlüsse — %s (EVA %d), Stand %s\n", $station, $eva, $data['generatedAt']->format('d.m.Y H:i'));
echo str_repeat('=', 64), "\n";

$disruptions = array_filter($data['messages'], fn ($m) => $m['cat'] !== 'Information');
if ($disruptions !== []) {
    echo "\n⚠ Störungslage:\n";
    foreach (array_slice($disruptions, 0, 6) as $m) {
        $span = $m['from'] ? ' (seit ' . $m['from']->format('d.m. H:i') . ')' : '';
        $aff  = ($m['affectedCount'] ?? 0) > 0
            ? ' — betrifft ' . $m['affectedCount'] . ' an dieser Station: '
                . implode(', ', array_slice($m['affected'], 0, 6))
            : '';
        echo '  - ' . $m['cat'] . $span . $aff . "\n";
    }
    echo "\n";
}

if ($data['waiting'] === []) {
    echo "Aktuell keine Anschluss-Entscheidungen gemeldet.\n";
} else {
    foreach ($data['waiting'] as $c) {
        /** @var Bahn\WaitingConnection $c */
        printf(
            "[%-10s] %-22s ab %s (%s) Gl.%-3s  %s  %-22s an %s (%s)\n",
            $c->statusLabel(),
            $c->outbound->name() . ' →' . ($c->outbound->destination() ?: '?'),
            $fmt($c->outbound->time()),
            $delay($c->outbound),
            $track($c->outbound),
            $c->relationLabel(),
            $c->inbound->name() . ' ←' . ($c->inbound->origin() ?: '?'),
            $fmt($c->inbound->time()),
            $delay($c->inbound)
        );
        echo '             ' . $c->statusSentence() . "\n";
    }
}

echo "\nNächste Abfahrten:\n";
foreach (array_slice($data['departures'], 0, 15) as $t) {
    $dest = $t->destination();
    if (strlen($dest) > 28) {
        $dest = substr($dest, 0, 27) . '…';
    }
    if ($t->isCancelled()) {
        printf("  %s %-12s %-28s %s\n", $fmt($t->time()), 'FÄLLT AUS', $t->fullName(), $dest);
    } else {
        printf("  %s %-6s %-12s  %-28s Gl.%s\n",
            $fmt($t->time()), $delay($t), $t->fullName(), $dest, $track($t));
    }
    if ($t->reasons !== []) {
        echo '        ↳ ' . implode(' · ', array_slice(array_unique($t->reasons), 0, 3)) . "\n";
    }
}
