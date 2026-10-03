<?php

namespace App\Http\Requests\Registry;

use App\Rules\RejectSecretBearingValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManagementAuthorizationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'resource_type' => ['required', Rule::in(['project', 'hosting_account'])],
            'resource_id' => ['required', 'integer'],
            'allowed_action_classes' => ['required', 'array', 'min:1'],
            'allowed_action_classes.*' => [Rule::in(['observe', 'backup', 'maintenance', 'renewal'])],
            'authorizer_label' => ['required', 'string', 'max:255', new RejectSecretBearingValue],
            'evidence_id' => ['nullable', 'integer'],
            'valid_until' => ['nullable', 'date'],
        ];
    }
}
