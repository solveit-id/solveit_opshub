<?php

namespace App\Infrastructure\Connectors;

enum CapabilityStatus: string
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case PermissionDenied = 'permission_denied';
    case NotConfigured = 'not_configured';
    case Unknown = 'unknown';
    case Fail = 'fail';
}
