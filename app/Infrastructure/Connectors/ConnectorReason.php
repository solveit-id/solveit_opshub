<?php

namespace App\Infrastructure\Connectors;

enum ConnectorReason: string
{
    case AuthFailed = 'AUTH_FAILED';
    case PermissionDenied = 'PERMISSION_DENIED';
    case Unsupported = 'UNSUPPORTED_CAPABILITY';
    case FeatureDisabled = 'PROVIDER_FEATURE_DISABLED';
    case NotConfigured = 'NOT_CONFIGURED';
    case InvalidConfiguration = 'INVALID_CONFIGURATION';
    case RateLimited = 'RATE_LIMITED';
    case Timeout = 'NETWORK_TIMEOUT';
    case Network = 'NETWORK_ERROR';
    case TlsInvalid = 'TLS_INVALID';
    case HostKeyMismatch = 'HOST_KEY_MISMATCH';
    case QuotaInsufficient = 'QUOTA_INSUFFICIENT';
    case SourceBusy = 'SOURCE_BUSY';
    case ArtifactIncomplete = 'ARTIFACT_INCOMPLETE';
    case IntegrityFailed = 'INTEGRITY_FAILED';
    case AuthorizationExpired = 'AUTHORIZATION_EXPIRED';
    case TargetBlocked = 'TARGET_BLOCKED';
    case PathBlocked = 'PATH_BLOCKED';
    case ResponseInvalid = 'RESPONSE_INVALID';
    case LimitExceeded = 'LIMIT_EXCEEDED';
    case ReconcileRequired = 'RECONCILE_REQUIRED';

    public function message(): string
    {
        return match ($this) {
            self::AuthFailed => 'Autentikasi connector gagal; tindakan write dihentikan.',
            self::PermissionDenied => 'Provider menolak izin tindakan ini.',
            self::Unsupported, self::FeatureDisabled => 'Kemampuan belum didukung pada target ini; gunakan monitoring publik dan runbook manual.',
            self::NotConfigured => 'Connector belum dikonfigurasi.',
            self::Timeout => 'Batas waktu koneksi tercapai; periksa keadaan source sebelum retry write.',
            self::TlsInvalid => 'Verifikasi sertifikat TLS gagal.',
            self::HostKeyMismatch => 'Fingerprint host SFTP tidak cocok; diperlukan review.',
            self::QuotaInsufficient => 'Kapasitas source atau storage tidak mencukupi.',
            self::ArtifactIncomplete => 'Artifact source belum selesai dan belum menjadi backup terverifikasi.',
            self::ReconcileRequired => 'Keadaan operasi remote harus direkonsiliasi dahulu.',
            default => 'Connector belum dapat menyelesaikan tindakan; periksa reason code dan konfigurasi terotorisasi.',
        };
    }
}
