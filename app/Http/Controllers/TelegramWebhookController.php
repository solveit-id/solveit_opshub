<?php

namespace App\Http\Controllers;

use App\Application\TelegramNotifications\TelegramWebhook;
use App\Models\TelegramBot;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBot $bot)
    {
        $receipt = app(TelegramWebhook::class)->receive($bot, $request);

        return response()->json(['ok' => true, 'receipt_id' => $receipt->id]);
    }
}
