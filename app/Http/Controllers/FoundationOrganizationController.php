<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\JsonResponse;

class FoundationOrganizationController extends Controller
{
    public function show(Organization $organization): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $organization->id,
            'name' => $organization->name,
            'timezone' => $organization->timezone,
            'status' => $organization->is_active ? 'active' : 'inactive',
        ]]);
    }
}
