<?php

namespace App\Application\RenewalFollowups;

use App\Models\ServiceSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class Expiry
{
    public function normalize(array $data): array
    {
        $precision = $data['date_precision'] ?? 'unknown';
        $zone = $data['source_timezone'] ?? null;
        if (! in_array($precision, ['unknown', 'date', 'instant'], true)
            || ($zone !== null && ! in_array($zone, timezone_identifiers_list(), true))) {
            throw ValidationException::withMessages(['date_precision' => 'Precision/timezone expiry tidak valid.']);
        }
        if ($precision === 'unknown') {
            if (! empty($data['expires_at']) || ! empty($data['expiry_date']) || $zone !== null) {
                throw ValidationException::withMessages(['date_precision' => 'Unknown tidak boleh memiliki expiry/timezone fiktif.']);
            }
            $data = [...$data, 'expires_at' => null, 'expiry_date' => null, 'source_timezone' => null];
        } elseif ($precision === 'date') {
            $date = $data['expiry_date'] ?? null;
            if (! $zone || ! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)) || ! empty($data['expires_at'])) {
                throw ValidationException::withMessages(['expiry_date' => 'Tanggal valid dan timezone sumber wajib; expires_at harus kosong.']);
            }
            $data['expires_at'] = null;
        } else {
            if (empty($data['expires_at']) || ! empty($data['expiry_date'])) {
                throw ValidationException::withMessages(['expires_at' => 'Instant wajib; expiry_date harus kosong.']);
            }
            $value = $data['expires_at'];
            if (! $zone && ! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', (string) $value)) {
                throw ValidationException::withMessages(['source_timezone' => 'Instant membutuhkan offset atau timezone sumber.']);
            }
            $data['expires_at'] = CarbonImmutable::parse($value, $zone ?? 'UTC')->utc()->format('Y-m-d H:i:s');
            $data['expiry_date'] = null;
            $data['source_timezone'] = $zone ?? 'UTC';
        }
        foreach (['billing_due_at', 'renew_by'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = CarbonImmutable::parse($data[$field], $zone ?? 'UTC')->utc()->format('Y-m-d H:i:s');
            }
        }

        return $data;
    }

    public function instant(array $snapshot): ?CarbonImmutable
    {
        return match ($snapshot['date_precision']) {
            'date' => CarbonImmutable::parse($snapshot['expiry_date'], $snapshot['source_timezone'])->endOfDay()->utc(),
            'instant' => CarbonImmutable::parse($snapshot['expires_at'], 'UTC')->utc(),
            default => null,
        };
    }

    public function snapshot(ServiceSubscription $service): array
    {
        return [...$service->only(['date_precision', 'source_timezone', 'source', 'evidence_id']),
            'expiry_date' => $service->expiry_date?->format('Y-m-d'), 'expires_at' => $service->expires_at?->utc()->format('Y-m-d H:i:s')];
    }
}
