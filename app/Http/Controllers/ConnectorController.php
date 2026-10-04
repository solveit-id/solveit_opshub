<?php

namespace App\Http\Controllers;

use App\Application\Connectors\CapabilityRecorder;
use App\Application\Connectors\ConnectorAccess;
use App\Application\Connectors\ConnectorRegistry;
use App\Application\Connectors\ConnectorTests;
use App\Infrastructure\Connectors\ConnectorConfig;
use App\Models\Connector;
use App\Models\ConnectorTestRun;
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
            'tests' => ConnectorTestRun::where('connector_id', $connector->id)->latest('id')->limit(20)->get(),
            'fallback' => 'Monitoring publik dan runbook manual; tidak ada write tanpa capability, otorisasi dan preflight teruji.']]);
    }

    public function test(Request $request, Organization $organization, Connector $connector)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'candidate_reference' => ['nullable', 'string', 'max:190']]);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[a-zA-Z0-9_.-]{8,100}$/D', $key), 422);
        try {
            $run = app(ConnectorTests::class)->enqueue($organization, $request->user(), $connector, $data['version'], $key, $data['candidate_reference'] ?? null);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['candidate_reference' => 'Secret reference tidak valid.']);
        }

        return response()->json(['data' => $run], 202);
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
