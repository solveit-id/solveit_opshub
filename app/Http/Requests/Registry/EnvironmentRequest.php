<?php

namespace App\Http\Requests\Registry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnvironmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['production', 'staging', 'development', 'custom'])],
            'display_name' => ['required', 'string', 'max:100'],
        ];
    }
}
