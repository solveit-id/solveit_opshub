<?php

namespace App\Infrastructure\Monitoring;

class CurlHttpTransport implements HttpTransport
{
    public function get(string $url, string $address, float $timeout, int $bodyLimit): array
    {
        $parts = parse_url($url);
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $handle = curl_init($url);
        $body = '';
        $location = null;
        $tooLarge = false;
        $headerBytes = 0;
        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT_MS => max(1, (int) ($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ($timeout * 1000)),
            CURLOPT_USERAGENT => 'Solveit-OpsHub/1.0',
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$tooLarge, $bodyLimit): int {
                if (strlen($body) + strlen($chunk) > $bodyLimit) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$location, &$headerBytes): int {
                $headerBytes += strlen($header);
                if ($headerBytes > 65536) {
                    return 0;
                }
                if (str_starts_with(strtolower($header), 'location:')) {
                    $location = trim(substr($header, 9));
                }

                return strlen($header);
            },
        ]);
        $success = curl_exec($handle);
        $error = curl_errno($handle);
        $info = curl_getinfo($handle);
        curl_close($handle);
        if ($success === false) {
            throw new ProbeTransportException($tooLarge ? 'BODY_LIMIT' : match ($error) {
                CURLE_OPERATION_TIMEDOUT => 'NETWORK_TIMEOUT',
                CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CONNECT_ERROR => 'TLS_INVALID',
                default => 'NETWORK_ERROR',
            });
        }

        return ['status' => $info['http_code'], 'peer' => $info['primary_ip'], 'body' => $body, 'location' => $location, 'latency_ms' => (int) round($info['total_time'] * 1000)];
    }
}
