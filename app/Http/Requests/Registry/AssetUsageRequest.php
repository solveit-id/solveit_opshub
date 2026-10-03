<?php

namespace App\Http\Requests\Registry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetUsageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
            'environment_id' => ['nullable', 'integer'],
            'purpose' => ['required', Rule::in(['public_endpoint', 'dns', 'hosting', 'repository', 'database', 'storage', 'service_subscription', 'other'])],
        ];
    }
}
