<?php

namespace App\Http\Controllers;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\TelegramNotifications\TelegramBindingService;
use App\Models\Organization;
use App\Models\TelegramBinding;
use App\Models\TelegramBindingIntent;
use App\Models\TelegramBot;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TelegramBindingController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        $binding = TelegramBinding::forOrganization($organization)->where('user_id', $request->user()->id)->first();
        $bot = TelegramBot::forOrganization($organization)->first();
        $current = $binding && $bot && app(TelegramBindingService::class)->current($bot, $binding->telegram_user_id);
        $data = ['organization' => $organization->only(['id', 'name']), 'binding' => $binding ? [...$binding->only(['id', 'telegram_user_id']), 'enabled' => (bool) $current] : null, 'intent' => TelegramBindingIntent::forOrganization($organization)->where('user_id', $request->user()->id)->latest('id')->first()];

        return $request->expectsJson() ? response()->json(['data' => $data]) : Inertia::render('Telegram/Binding', $data);
    }

    public function start(Request $request, Organization $organization)
    {
        return response()->json(['data' => app(TelegramBindingService::class)->start($organization, $request->user())], 201);
    }

    public function confirm(Request $request, Organization $organization, TelegramBindingIntent $intent)
    {
        $data = $request->validate(['telegram_user_id' => ['required', 'string', 'regex:/^[1-9]\d{0,18}$/'], 'private_identity_confirmed' => ['accepted']]);

        return response()->json(['data' => app(TelegramBindingService::class)->confirm($organization, $request->user(), $intent, $data['telegram_user_id'])]);
    }

    public function revoke(Request $request, Organization $organization)
    {
        $binding = TelegramBinding::forOrganization($organization)->where('user_id', $request->user()->id)->firstOrFail();
        $binding->update(['enabled' => false]);
        app(AuditWriter::class)->write($organization, 'telegram.binding_revoked', 'TelegramBinding', $binding->id, 'success', $request->user());

        return response()->json(['data' => ['enabled' => false]]);
    }
}
