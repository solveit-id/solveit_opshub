<?php

namespace App\Infrastructure\Connectors;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ConnectorResult implements \JsonSerializable
{
    public CarbonImmutable $observedAt;

    public string $message;

    public array $evidence;

    public function __construct(
        public string $status,
        public string $capability,
        public ?string $reasonCode,
        string $message = '',
        public bool $fake = false,
        ?CarbonImmutable $observedAt = null,
        array $evidence = [],
        public bool $retryable = false,
    ) {
        CapabilityStatus::from($status);
        Capability::from($capability);
        $reason = $reasonCode === null ? null : ConnectorReason::from($reasonCode);
        if (($status !== 'supported' && $reason === null)
            || ($status === 'supported' && $reason !== null)
            || ($retryable && ! in_array($reason, [ConnectorReason::Timeout, ConnectorReason::Network, ConnectorReason::RateLimited, ConnectorReason::SourceBusy], true))) {
            throw new InvalidArgumentException('Inconsistent normalized connector result.');
        }
        // Deliberately discard provider/freeform messages and unknown evidence keys.
        $this->message = $reason?->message() ?? 'Kemampuan teramati; periksa sumber, waktu dan batas validasinya.';
        $this->observedAt = ($observedAt ?? CarbonImmutable::now('UTC'))->utc();
        $safe = [];
        foreach (['http_status', 'quota_bytes', 'used_bytes', 'file_count', 'bytes', 'latency_ms'] as $key) {
            if (array_key_exists($key, $evidence) && ($evidence[$key] === null || (is_int($evidence[$key]) && $evidence[$key] >= 0))) {
                $safe[$key] = $evidence[$key];
            }
        }
        $this->evidence = $safe;
    }

    public function successful(): bool
    {
        return $this->status === CapabilityStatus::Supported->value;
    }

    public function jsonSerialize(): array
    {
        return ['status' => $this->status, 'capability' => $this->capability, 'reason_code' => $this->reasonCode,
            'message' => $this->message, 'observed_at' => $this->observedAt->toIso8601String(),
            'evidence' => $this->evidence, 'retryable' => $this->retryable, 'source' => $this->fake ? 'fake' : 'provider', 'fake' => $this->fake];
    }
}
