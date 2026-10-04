<?php

namespace App\Http\Controllers;

use App\Application\PolicyScheduling\IdempotencyService;
use App\Application\TelegramNotifications\TelegramConfiguration;
use App\Models\IdempotencyKey;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TelegramConfigurationController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        app(TelegramConfiguration::class)->owner($organization, $request->user());
        $data = ['organization' => $organization->only(['id', 'name']), 'bot' => TelegramBot::forOrganization($organization)->first(),
            'destinations' => TelegramDestination::forOrganization($organization)->get(), 'projects' => Project::forOrganization($organization)->get(['id', 'name']),
            'members' => Membership::where('organization_id', $organization->id)->where('is_active', true)->with('user')->get()->filter(fn ($m) => $m->user?->is_active)->map(fn ($m) => ['id' => $m->user_id, 'name' => $m->user->name, 'role' => $m->role->value])->values(),
            'liveEnabled' => (bool) config('opshub.live_connectors_enabled')];

        return $request->expectsJson() ? response()->json(['data' => $data]) : Inertia::render('Telegram/Settings', $data);
    }

    public function bot(Request $request, Organization $organization)
    {
        return response()->json(['data' => app(TelegramConfiguration::class)->bot($organization, $request->user(), $request->all())]);
    }

    public function identity(Request $request, Organization $organization)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => app(TelegramConfiguration::class)->identity($organization, $request->user(), $data['version'])]);
    }

    public function destination(Request $request, Organization $organization, ?TelegramDestination $destination = null)
    {
        abort_unless(! $destination || $destination->organization_id === $organization->id, 404);

        return response()->json(['data' => app(TelegramConfiguration::class)->destination($organization, $request->user(), $request->all(), $destination)], $destination ? 200 : 201);
    }

    public function test(Request $request, Organization $organization, TelegramDestination $destination)
    {
        app(TelegramConfiguration::class)->owner($organization, $request->user());
        abort_unless($destination->organization_id === $organization->id, 404);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[a-zA-Z0-9-]{8,80}$/', $key), 422);
        $result = DB::transaction(function () use ($request, $organization, $destination, $data, $key) {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            app(TelegramConfiguration::class)->owner($organization->fresh(), $request->user()->fresh());
            $hash = hash('sha256', json_encode([$destination->id, $data], JSON_THROW_ON_ERROR));
            $existing = IdempotencyKey::where('organization_id', $organization->id)->where('actor_user_id', $request->user()->id)->where('action', 'telegram.test')->where('key', $key)->first();
            if ($existing) {
                abort_unless($existing->request_hash === $hash, 409);

                return $existing->response;
            }
            $result = ['outbox_event_id' => app(TelegramConfiguration::class)->testIntent($organization, $request->user(), $destination, $data['version']), 'status' => 'pending'];
            app(IdempotencyService::class)->remember($organization, $request->user(), 'telegram.test', $key, $hash, $result, now('UTC')->addDay());

            return $result;
        });

        return response()->json(['data' => $result], 202);
    }
}
