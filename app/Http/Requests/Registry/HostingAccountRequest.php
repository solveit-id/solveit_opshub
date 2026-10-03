<?php

namespace App\Http\Requests\Registry;

use App\Rules\RejectSecretBearingValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HostingAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'integer'],
            'provider' => ['required', 'string', 'max:255', new RejectSecretBearingValue],
            'panel_type' => ['required', Rule::in(['cpanel', 'plesk', 'hpanel', 'custom', 'none', 'unknown'])],
            'hostname' => ['nullable', 'string', 'max:255', new RejectSecretBearingValue],
            'api_endpoint' => ['nullable', 'url:https', 'max:2048', new RejectSecretBearingValue],
            'account_identifier' => ['nullable', 'string', 'max:255', new RejectSecretBearingValue],
            'quota_bytes' => ['nullable', 'integer', 'min:0'],
            'available_access' => ['required', 'array'],
            'available_access.*' => [Rule::in(['panel_read', 'api_read', 'sftp_read', 'backup_read', 'manual_only', 'unknown'])],
            'environment_roots' => ['present', 'array'],
            'environment_roots.*' => ['string', 'max:1024', new RejectSecretBearingValue],
        ];
    }
}
