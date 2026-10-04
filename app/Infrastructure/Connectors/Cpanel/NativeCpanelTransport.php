<?php

namespace App\Infrastructure\Connectors\Cpanel;

use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\ConnectorSecretResolver;
use App\Infrastructure\Connectors\ConnectorTargetGuard;
use App\Infrastructure\Security\LiveConnectorGate;
use DomainException;
use Throwable;

class NativeCpanelTransport implements CpanelTransport
{
    public function __construct(private readonly ConnectorTargetGuard $targets, private readonly ConnectorSecretResolver $secrets) {}

    public function read(ConnectorConfig $config, CpanelRead $operation): CpanelResponse
    {
        if (! app()->runningInConsole() || $config->kind !== 'cpanel' || ! config('opshub.live_connectors_enabled')) {
            return new CpanelResponse(0, error: ConnectorReason::NotConfigured);
        }
        try {
            app(LiveConnectorGate::class)->assertEnabled();
            $addresses = $this->targets->addresses($config);
        } catch (DomainException) {
            return new CpanelResponse(0, error: ConnectorReason::TargetBlocked);
        }
        try {
            $token = $this->secrets->resolve($config->secretReference);
            if (! preg_match('/^[a-zA-Z0-9]{16,256}$/D', $token)) {
                return new CpanelResponse(0, error: ConnectorReason::InvalidConfiguration);
            }
        } catch (Throwable) {
            return new CpanelResponse(0, error: ConnectorReason::NotConfigured);
        }
        $parts = parse_url($config->endpoint);
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? 443;
        $address = $addresses[0];
        $handle = curl_init(rtrim($config->endpoint, '/').'/execute/'.$operation->value);
        $body = '';
        $headerBytes = 0;
        $overLimit = false;
        curl_setopt_array($handle, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)],
            CURLOPT_HTTPHEADER => ['Authorization: cpanel '.$config->accountIdentifier.':'.$token, 'Accept: application/json'],
            CURLOPT_TIMEOUT_MS => 10000, CURLOPT_CONNECTTIMEOUT_MS => 5000, CURLOPT_USERAGENT => 'Solveit-OpsHub/1.0',
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$overLimit): int {
                if (strlen($body) + strlen($chunk) > 1048576) {
                    $overLimit = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            }, CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$headerBytes): int {
                $headerBytes += strlen($header);

                return $headerBytes > 65536 ? 0 : strlen($header);
            }]);
        $success = curl_exec($handle);
        $error = curl_errno($handle);
        $info = curl_getinfo($handle);
        curl_close($handle);
        unset($token);
        if ($success === false) {
            return new CpanelResponse(0, error: $overLimit || $headerBytes > 65536 ? ConnectorReason::LimitExceeded : match ($error) {
                CURLE_OPERATION_TIMEDOUT => ConnectorReason::Timeout,
                CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CONNECT_ERROR => ConnectorReason::TlsInvalid,
                default => ConnectorReason::Network,
            });
        }
        if (inet_pton($info['primary_ip']) !== inet_pton($address)) {
            return new CpanelResponse(0, error: ConnectorReason::TargetBlocked);
        }
        $decoded = json_decode($body, true, 32);

        return new CpanelResponse($info['http_code'], is_array($decoded) ? $decoded : [], is_array($decoded) ? null : ConnectorReason::ResponseInvalid);
    }
}
