<?php

return [
    'timezone' => env('OPSHUB_TIMEZONE', 'Asia/Jakarta'),
    'live_connectors_enabled' => (bool) env('LIVE_CONNECTORS_ENABLED', false),
    'public_probes_enabled' => (bool) env('OPSHUB_PUBLIC_PROBES_ENABLED', false),
    'require_owner_mfa' => (bool) env('OPSHUB_REQUIRE_OWNER_MFA', false),
    'step_up_ttl_minutes' => (int) env('OPSHUB_STEP_UP_TTL_MINUTES', 10),
    'outbound_allowed_ports' => array_map('intval', explode(',', env('OPSHUB_OUTBOUND_ALLOWED_PORTS', '80,443'))),
    'queue' => [
        'critical' => env('OPSHUB_QUEUE_CRITICAL', 'critical'),
        'notification' => env('OPSHUB_QUEUE_NOTIFICATION', 'notification'),
        'probe' => env('OPSHUB_QUEUE_PROBE', 'probe'),
        'routine' => env('OPSHUB_QUEUE_ROUTINE', 'routine'),
        'backup' => env('OPSHUB_QUEUE_BACKUP', 'backup'),
    ],
];
