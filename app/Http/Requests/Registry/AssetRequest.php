<?php

namespace App\Http\Requests\Registry;

use App\Rules\RejectSecretBearingValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['url', 'domain', 'hosting_account', 'provider', 'repository', 'database', 'storage_destination', 'service_subscription'])],
            'canonical_identity' => ['required', 'string', 'max:2048', new RejectSecretBearingValue],
            'responsibility' => ['required', Rule::in(['solveit', 'client', 'provider', 'shared'])],
            'owner_user_id' => ['nullable', 'integer'],
            'source' => ['required', 'string', 'max:255', new RejectSecretBearingValue],
            'verified_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000', new RejectSecretBearingValue],
        ];
    }
}
