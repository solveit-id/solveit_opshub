<?php

namespace App\Http\Controllers;

use App\Application\Connectors\CapabilityRecorder;
use App\Application\Connectors\ConnectorAccess;
use App\Application\Connectors\ConnectorRegistry;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Models\Connector;
use App\Models\HostingAccount;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ConnectorController extends Controller
{
    public function show(Request $request, Organization $organization, Connector $connector)
    {
        abort_unless($connector->organization_id === $organization->id, 404);
        app(ConnectorAccess::class)->requireAccount($organization, $request->user(), HostingAccount::findOrFail($connector->hosting_account_id));

        return response()->json(['data' => ['connector' => $connector, 'capabilities' => app(CapabilityRecorder::class)->current($connector),
            'fallback' => 'Monitoring publik dan runbook manual; tidak ada write tanpa capability, otorisasi dan preflight teruji.']]);
    }

    public function configure(Request $request, Organization $organization)
    {
        $data = $request->validate(['hosting_account_id' => ['required', 'integer'], 'kind' => ['required', 'in:cpanel,sftp'],
            'endpoint' => ['required', 'string', 'max:512'], 'account_identifier' => ['required', 'string', 'max:64'],
            'secret_reference' => ['required', 'string', 'max:190'], 'roots' => ['sometimes', 'array', 'max:20'],
            'roots.*' => ['string', 'max:512'], 'host_fingerprint' => ['nullable', 'string', 'max:64'], 'version' => ['nullable', 'integer', 'min:1']]);
        try {
            $config = new ConnectorConfig($organization->id, $data['hosting_account_id'], $data['kind'], $data['endpoint'],
                $data['account_identifier'], $data['secret_reference'], $data['roots'] ?? [], $data['host_fingerprint'] ?? null);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['configuration' => 'Endpoint, reference secret, atau root/fingerprint connector tidak valid.']);
        }
        $connector = app(ConnectorRegistry::class)->configure($organization, $request->user(), $config, $data['version'] ?? null);

        return response()->json(['data' => $connector], $data['version'] ?? null ? 200 : 201);
    }
}
