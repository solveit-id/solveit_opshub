<?php

namespace App\Http\Controllers;

use App\Application\ClientTemplates\TemplateGovernance;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientTemplateController extends Controller
{
    public function index(Request $request, Organization $organization, TemplateGovernance $service): JsonResponse
    {
        $service->requireOwner($organization, $request->user());
        $service->seed($organization);
        $data = MessageTemplate::forOrganization($organization)->orderBy('template_key')->get()->map(function ($template) use ($organization) {
            $current = MessageTemplateVersion::forOrganization($organization)->where('message_template_id', $template->id)->where('version', $template->published_version)->firstOrFail();

            return [...$template->only(['template_key', 'version', 'published_version', 'locale']), 'current' => $current->only(['body', 'mandatory_variables', 'allowed_variables', 'trigger', 'published_at'])];
        });

        return response()->json(['data' => $data]);
    }

    public function preview(Request $request, Organization $organization, string $key, TemplateGovernance $service): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:20000'], 'variables' => ['present', 'array'], 'variables.*' => ['nullable', 'string', 'max:20000']]);

        return response()->json(['data' => $service->preview($organization, $request->user(), $key, $data['body'], $data['variables'])]);
    }

    public function publish(Request $request, Organization $organization, string $key, TemplateGovernance $service): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:20000'], 'version' => ['required', 'integer', 'min:1']]);
        $published = $service->publish($organization, $request->user(), $key, $data['version'], $data['body']);

        return response()->json(['data' => $published->only(['version', 'published_at'])], 201);
    }
}
