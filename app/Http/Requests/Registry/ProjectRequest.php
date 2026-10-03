<?php

namespace App\Http\Requests\Registry;

use App\Models\Organization;
use App\Rules\RejectSecretBearingValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');
        $projectId = $this->route('project')?->id;

        return [
            'client_id' => [
                'required',
                Rule::exists('clients', 'id')->where('organization_id', $organization->id),
            ],
            'code' => [
                'required',
                'string',
                'max:64',
                'alpha_dash:ascii',
                Rule::unique('projects', 'code')
                    ->where('organization_id', $organization->id)
                    ->ignore($projectId),
            ],
            'name' => ['required', 'string', 'max:255', new RejectSecretBearingValue],
            'lifecycle' => ['required', Rule::in(['draft', 'onboarding', 'active', 'paused', 'archived'])],
            'criticality' => ['required', Rule::in(['low', 'normal', 'high', 'critical'])],
            'internal_pic_user_id' => ['nullable', 'integer'],
            'stack_tags' => ['nullable', 'array', 'max:20'],
            'stack_tags.*' => ['string', 'max:64', 'distinct', new RejectSecretBearingValue],
            'notes' => ['nullable', 'string', 'max:5000', new RejectSecretBearingValue],
        ];
    }
}
