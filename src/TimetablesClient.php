<?php
declare(strict_types=1);

namespace Bahn;

/**
 * Thin client for the DB API Marketplace "Timetables" (IRIS) API.
 *
 * @see https://developers.deutschebahn.com/db-api-marketplace/apis/product/timetables
 */
final class TimetablesClient implements TimetablesSource
{
    private const BASE = 'https://apis.deutschebahn.com/db-api-marketplace/apis/timetables/v1';

    public function __construct(
        private string $clientId,
        private string $apiKey,
        private int $timeout = 25
    ) {
    }

    /** Resolve a station name/pattern to its primary EVA number. */
    public function resolveEva(string $pattern): ?int
    {
        $xml = $this->get('/station/' . rawurlencode($pattern));
        if ($xml === null || !isset($xml->station[0])) {
            return null;
        }
        $eva = (string) $xml->station[0]['eva'];
        return $eva === '' ? null : (int) $eva;
    }

    /**
     * Station metadata for an EVA (its own EVA plus sibling "meta" EVAs).
     * @return array{eva:int,meta:int[],name:string}|null
     */
    public function station(int $eva): ?array
    {
        $xml = $this->get('/station/' . $eva);
        if ($xml === null || !isset($xml->station[0])) {
            return null;
        }
        $s    = $xml->station[0];
        $meta = [];
        foreach (explode('|', (string) $s['meta']) as $m) {
            $m = trim($m);
            if (preg_match('/^\d{7,8}$/', $m)) {
                $meta[] = (int) $m;
            }
        }
        return ['eva' => (int) $s['eva'], 'meta' => $meta, 'name' => (string) $s['name']];
    }

    /** Planned timetable for one hour. $date = YYMMDD, $hour = HH. */
    public function plan(int $eva, string $date, string $hour): ?\SimpleXMLElement
    {
        return $this->get(sprintf('/plan/%d/%s/%s', $eva, $date, $hour));
    }

    /** Full known changes for a station (delays, platform changes, connections). */
    public function fchg(int $eva): ?\SimpleXMLElement
    {
        return $this->get('/fchg/' . $eva);
    }

    private function get(string $path): ?\SimpleXMLElement
    {
        $url = self::BASE . $path;
        $headers = [
            'DB-Client-Id: ' . $this->clientId,
            'DB-Api-Key: ' . $this->apiKey,
            'Accept: application/xml',
        ];

        $body = $this->fetch($url, $headers);
        if ($body === null || trim($body) === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($prev);

        return $xml === false ? null : $xml;
    }

    /** @param string[] $headers */
    private function fetch(string $url, array $headers): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code >= 400) {
                return null;
            }
            return (string) $body;
        }

        // Fallback when ext-curl is unavailable.
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        return $body === false ? null : $body;
    }
}
