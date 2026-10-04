<?php

namespace App\Application\PolicyScheduling;

use App\Rules\RejectSecretBearingValue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MonitoringPolicyConfiguration
{
    public const CheckIntervals = ['http' => 60, 'tls' => 43200, 'dns' => 21600];

    public function defaults(string $timezone = 'Asia/Jakarta'): array
    {
        return [
            'timezone' => $timezone,
            'checks' => [
                'http' => ['enabled' => true, 'interval_seconds' => 60, 'failure_threshold' => 3, 'recovery_threshold' => 2],
                'tls' => ['enabled' => true, 'interval_seconds' => 43200, 'warning_days' => 30, 'critical_days' => 7],
                'dns' => ['enabled' => true, 'interval_seconds' => 21600],
            ],
        ];
    }

    public function validate(array $configuration): void
    {
        Validator::make($configuration, [
            'checks' => ['required', 'array:http,tls,dns'],
            'checks.http' => ['required', 'array:enabled,interval_seconds,failure_threshold,recovery_threshold,allowed_statuses,timeout_seconds,body_limit,redirect_limit,expected_text'],
            'checks.http.allowed_statuses' => ['sometimes', 'array', 'min:1', 'max:500'],
            'checks.http.allowed_statuses.*' => ['integer', 'between:100,599'],
            'checks.http.timeout_seconds' => ['sometimes', 'integer', 'between:1,10'],
            'checks.http.body_limit' => ['sometimes', 'integer', 'between:1,1048576'],
            'checks.http.redirect_limit' => ['sometimes', 'integer', 'between:0,5'],
            'checks.http.expected_text' => ['sometimes', 'nullable', 'string', 'min:1', 'max:1024', new RejectSecretBearingValue],
            'checks.tls' => ['required', 'array:enabled,interval_seconds,warning_days,critical_days'],
            'checks.dns' => ['required', 'array:enabled,interval_seconds,record_type,expected_values'],
            'checks.dns.record_type' => ['sometimes', 'in:A,AAAA,CNAME,MX,NS'],
            'checks.dns.expected_values' => ['sometimes', 'array', 'min:1', 'max:100'],
            'checks.dns.expected_values.*' => ['string', 'max:253', 'regex:/^[a-zA-Z0-9.:\-]+$/'],
        ])->validate();
        foreach (['timezone', 'checks'] as $key) {
            if (! array_key_exists($key, $configuration)) {
                throw ValidationException::withMessages(['configuration' => "Konfigurasi policy membutuhkan {$key}."]);
            }
        }
        if (! in_array($configuration['timezone'], timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['configuration.timezone' => 'Timezone harus berupa identifier IANA.']);
        }
        foreach (self::CheckIntervals as $check => $minimum) {
            $setting = $configuration['checks'][$check] ?? null;
            if (! is_array($setting) || ! array_key_exists('enabled', $setting) || ! array_key_exists('interval_seconds', $setting)) {
                throw ValidationException::withMessages(["configuration.checks.{$check}" => 'Setiap check memerlukan enabled dan interval_seconds.']);
            }
            if (! is_bool($setting['enabled']) || ! is_int($setting['interval_seconds']) || $setting['interval_seconds'] < $minimum) {
                throw ValidationException::withMessages(["configuration.checks.{$check}.interval_seconds" => "Interval {$check} tidak boleh kurang dari {$minimum} detik."]);
            }
        }
        $http = $configuration['checks']['http'];
        if (($http['failure_threshold'] ?? null) !== 3 || ($http['recovery_threshold'] ?? null) !== 2) {
            throw ValidationException::withMessages(['configuration.checks.http' => 'Policy M1 menggunakan 3 kegagalan dan 2 keberhasilan untuk recovery.']);
        }
        $tls = $configuration['checks']['tls'];
        if (($tls['warning_days'] ?? null) !== 30 || ($tls['critical_days'] ?? null) !== 7) {
            throw ValidationException::withMessages(['configuration.checks.tls' => 'Policy M1 menggunakan TLS warning 30 hari dan critical 7 hari.']);
        }
    }
}
