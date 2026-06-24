<?php
declare(strict_types=1);

namespace Bahn;

/** Minimal HTTP GET helper (curl when available, stream wrapper otherwise). */
final class Http
{
    /**
     * @param string[] $headers
     * @return string|null Response body for any HTTP status (so callers can
     *   read 4xx error payloads, e.g. a 404 "no matches"); null only on a
     *   transport-level failure (DNS, connection, timeout).
     */
    public static function get(string $url, array $headers, int $timeout = 12): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            return $body === false ? null : (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        return $body === false ? null : $body;
    }
}
