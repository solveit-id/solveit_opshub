<?php

namespace App\Http\Requests\Registry;

use App\Rules\RejectSecretBearingValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255', new RejectSecretBearingValue],
            'contact_value' => ['nullable', 'string', 'max:255', new RejectSecretBearingValue],
            'purpose' => ['nullable', 'string', 'max:255', new RejectSecretBearingValue],
            'preferred_manual_channel' => ['nullable', Rule::in(['telegram', 'whatsapp', 'email', 'phone', 'other'])],
            'verified_at' => ['nullable', 'date'],
        ];
    }
}
