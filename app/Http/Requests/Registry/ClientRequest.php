<?php

namespace App\Http\Requests\Registry;

use App\Rules\RejectSecretBearingValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new RejectSecretBearingValue],
            'status' => ['required', Rule::in(['active', 'paused', 'archived'])],
            'notes' => ['nullable', 'string', 'max:5000', new RejectSecretBearingValue],
        ];
    }
}
