<?php

namespace App\Infrastructure\Monitoring;

use App\Infrastructure\Security\OutboundTargetValidator;
use DomainException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

class HttpProbe
{
    public function __construct(private readonly OutboundTargetValidator $validator, private readonly HttpTransport $transport) {}

    public function check(string $url, array $options = []): ProbeResult
    {
        $timeout = min(10, max(1, (int) ($options['timeout_seconds'] ?? 10)));
        $limit = min(1048576, max(1, (int) ($options['body_limit'] ?? 1048576)));
        $redirectLimit = min(5, max(0, (int) ($options['redirect_limit'] ?? 5)));
        $deadline = microtime(true) + $timeout;
        $statuses = [];
        try {
            for ($hop = 0; ; $hop++) {
                $addresses = $this->validator->addresses($url);
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new ProbeTransportException('NETWORK_TIMEOUT');
                }
                $response = $this->transport->get($url, $addresses[0], $remaining, $limit);
                if (! in_array($response['peer'], $addresses, true) || ! $this->validator->isPublicAddress($response['peer'])) {
                    throw new DomainException('Actual peer differs from validated target.');
                }
                if (strlen($response['body']) > $limit) {
                    throw new ProbeTransportException('BODY_LIMIT');
                }
                $status = (int) $response['status'];
                $statuses[] = $status;
                if ($status >= 300 && $status <= 399 && $response['location'] !== null) {
                    if ($hop >= $redirectLimit) {
                        return new ProbeResult('fail', 'REDIRECT_LIMIT', ['redirect_statuses' => $statuses]);
                    }
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($response['location']));

                    continue;
                }
                $expected = $options['expected_text'] ?? null;
                $matched = $expected === null ? null : str_contains($response['body'], $expected);
                $allowed = $options['allowed_statuses'] ?? range(200, 299);
                $passed = in_array($status, $allowed, true) && $matched !== false;

                return new ProbeResult($passed ? 'pass' : 'fail', $passed ? 'HTTP_OK' : ($matched === false ? 'CONTENT_MISMATCH' : 'HTTP_STATUS'), [
                    'status_code' => $status, 'latency_ms' => (int) round(($timeout - ($deadline - microtime(true))) * 1000),
                    'redirect_statuses' => $statuses, 'content_check' => $matched === null ? 'not_configured' : ($matched ? 'match' : 'mismatch'),
                ]);
            }
        } catch (DomainException) {
            return new ProbeResult('unknown', 'TARGET_BLOCKED');
        } catch (ProbeTransportException $exception) {
            return new ProbeResult('fail', $exception->getMessage());
        }
    }
}
