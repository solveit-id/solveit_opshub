<?php

namespace App\Http\Requests\Registry;

use App\Rules\RejectSecretBearingValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ServiceSubscriptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'integer'],
            'billing_party' => ['required', Rule::in(['solveit', 'client', 'shared', 'provider', 'unknown'])],
            'billing_due_at' => ['nullable', 'date'],
            'paying_party' => ['required', Rule::in(['solveit', 'client', 'shared', 'provider', 'unknown'])],
            'action_owner' => ['required', Rule::in(['solveit', 'client', 'shared', 'provider', 'unknown'])],
            'expires_at' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'date_precision' => ['required', Rule::in(['instant', 'date', 'unknown'])],
            'source_timezone' => ['nullable', 'timezone'],
            'source' => ['required', 'string', 'max:255', new RejectSecretBearingValue],
            'evidence_id' => ['nullable', 'integer'],
            'reminder_policy' => ['required', 'array'],
            'reminder_policy.lead_days' => ['required', 'array', 'min:1'],
            'reminder_policy.lead_days.*' => ['integer', 'min:0', 'max:3650', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $precision = $this->input('date_precision');
            $hasInstant = $this->filled('expires_at');
            $hasDate = $this->filled('expiry_date');

            if ($precision === 'instant' && (! $hasInstant || $hasDate)) {
                $validator->errors()->add('expires_at', 'Precision instant membutuhkan expires_at dan tidak boleh memiliki expiry_date.');
            }
            if ($precision === 'date' && (! $hasDate || $hasInstant || ! $this->filled('source_timezone'))) {
                $validator->errors()->add('expiry_date', 'Precision date membutuhkan expiry_date dan source_timezone, tanpa expires_at.');
            }
            if ($precision === 'unknown' && ($hasInstant || $hasDate || $this->filled('source_timezone'))) {
                $validator->errors()->add('date_precision', 'Tanggal unknown harus menyimpan seluruh nilai expiry dan timezone sebagai null.');
            }
        });
    }
}
