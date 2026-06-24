<?php
declare(strict_types=1);

/**
 * Tests for StadaClient: result mapping/ranking and the local cache (a repeated
 * query must not hit the API again). Uses an injected HTTP stub — no network.
 *
 * Run: php tests/stada_client_test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Bahn\StadaClient;

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

$payload = json_encode(['result' => [
    ['name' => 'Köln Messe/Deutz', 'category' => 2, 'mailingAddress' => ['city' => 'Köln'],
     'evaNumbers' => [['number' => 8003368, 'isMain' => true]]],
    ['name' => 'Köln Hbf', 'category' => 1, 'mailingAddress' => ['city' => 'Köln'],
     'evaNumbers' => [['number' => 8000207, 'isMain' => true]]],
    ['name' => 'Frechen-Königsdorf', 'category' => 5, 'mailingAddress' => ['city' => 'Frechen'],
     'evaNumbers' => [['number' => 8003391, 'isMain' => true]]],
]]);

$calls = 0;
$stub = function (string $url, array $headers) use ($payload, &$calls): ?string {
    $calls++;
    return $payload;
};

$cacheDir = sys_get_temp_dir() . '/bahn_stada_test_' . getmypid();
@array_map('unlink', glob($cacheDir . '/*') ?: []);
$client = new StadaClient('cid', 'key', $cacheDir, 604800, $stub);

$r1 = $client->search('köln', 10);
check('returns 3 results', 3, count($r1));
check('prefix+category ranks Köln Hbf first', 'Köln Hbf', $r1[0]['name']);
check('main eva resolved', 8000207, $r1[0]['eva']);
check('city mapped', 'Köln', $r1[0]['city']);
check('non-prefix match ranks last', 'Frechen-Königsdorf', $r1[2]['name']);
check('one API call so far', 1, $calls);

// Second identical query must be served from cache (no extra API call).
$r2 = $client->search('köln', 10);
check('cache hit -> still one API call', 1, $calls);
check('cached result identical', $r1, $r2);

// API failure (transport) with no cache -> empty, NOT cached (so it retries).
$failCalls = 0;
$failStub = function (string $url, array $headers) use (&$failCalls): ?string { $failCalls++; return null; };
$client2 = new StadaClient('cid', 'key', $cacheDir . '_x', 604800, $failStub);
check('transport failure yields empty', [], $client2->search('nirgendwo', 10));
$client2->search('nirgendwo', 10);
check('transport failure is NOT cached (retried)', 2, $failCalls);

// 404 "no matches" -> empty AND cached (negative cache spares the rate limit).
$nfCalls = 0;
$nf = function (string $url, array $headers) use (&$nfCalls): ?string {
    $nfCalls++;
    return '{"errNo":404,"errMsg":"Not Found"}';
};
$client3 = new StadaClient('cid', 'key', $cacheDir . '_y', 604800, $nf);
check('404 yields empty', [], $client3->search('koeln', 10));
$client3->search('koeln', 10);
check('404 negative result IS cached (one call)', 1, $nfCalls);
@array_map('unlink', glob($cacheDir . '_y/*') ?: []);
@rmdir($cacheDir . '_y');

@array_map('unlink', glob($cacheDir . '/*') ?: []);
@rmdir($cacheDir);
echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n{$failures} TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
