<?php

namespace App\Http\Requests\Policy;

use Illuminate\Foundation\Http\FormRequest;

class PolicyAssignmentRequest extends FormRequest
{
    public function rules(): array
    {
        return ['project_id' => ['required', 'integer'], 'overrides' => ['present', 'array']];
    }
}
