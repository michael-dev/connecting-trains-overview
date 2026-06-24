<?php
declare(strict_types=1);

/**
 * Station autocomplete: GET ?q=<text> -> JSON [{name, eva, city}], best first.
 *
 * Suggestions merge the cached StaDa station-data API with the bundled offline
 * directory (de-duplicated by EVA), ranked by station importance so top
 * stations always rank first. The offline directory also covers StaDa outages
 * and ASCII-spelled umlauts (e.g. "koeln").
 */

use function Bahn\make_suggester;

require __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$q = substr($q, 0, 40); // bound the query — no value in longer station names
if (strlen($q) < 2) {
    echo '[]';
    return;
}

try {
    $items = make_suggester(__DIR__ . '/../../config.yaml')->suggest($q, 10);
    echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    echo '[]';
}
