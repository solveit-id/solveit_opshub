<?php

namespace App\Application\TelegramNotifications;

use App\Application\ClientTemplates\DraftGenerator;
use App\Application\IdentityAccess\ProjectAccess;
use App\Application\RenewalFollowups\RenewalAccess;
use App\Infrastructure\Telegram\TelegramTransport;
use App\Models\ClientFollowup;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\TelegramBot;
use App\Models\TelegramUpdateReceipt;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TelegramUpdateProcessor
{
    public function process(int $id): void
    {
        $receipt = TelegramUpdateReceipt::findOrFail($id);
        $answer = DB::transaction(function () use ($receipt) {
            $org = Organization::whereKey($receipt->organization_id)->lockForUpdate()->firstOrFail();
            $receipt = TelegramUpdateReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status !== 'pending') {
                return null;
            }
            $bot = TelegramBot::findOrFail($receipt->telegram_bot_id);
            if (! $org->is_active || ! $bot->enabled || $bot->identity_version !== $receipt->identity_version) {
                $receipt->update(['status' => 'processed', 'result_code' => 'RECEIVER_DISABLED', 'processed_at' => now('UTC')]);

                return null;
            }
            $payload = json_decode(Crypt::decryptString($receipt->encrypted_payload), true, flags: JSON_THROW_ON_ERROR);
            $binding = app(TelegramBindingService::class)->current($bot, $payload['telegram_user_id']);
            try {
                $code = DB::transaction(function () use ($org, $bot, $receipt, $payload, $binding) {
                    if ($payload['kind'] === 'binding') {
                        $accepted = app(TelegramBindingService::class)->candidate($bot, $payload['token_hash'], $payload['telegram_user_id'], $payload['chat_id'], $payload['chat_type']);
                        app(TelegramPrivateReply::class)->write($receipt, $payload, $accepted ? 'Kandidat diterima. Kembali ke dashboard OpsHub dan konfirmasikan ID Telegram Anda. Binding belum aktif.' : 'Binding ditolak/kedaluwarsa. Buat intent baru melalui dashboard; hanya private chat diizinkan.');

                        return $accepted ? 'BINDING_CANDIDATE' : 'BINDING_DENIED';
                    }
                    if ($payload['kind'] === 'callback') {
                        abort_unless($binding, 403);

                        return app(TelegramCallbacks::class)->act($bot, $binding, $receipt, $payload);
                    }
                    if (in_array($payload['command'], ['help', 'start'], true)) {
                        app(TelegramPrivateReply::class)->write($receipt, $payload, 'Solveit OpsHub: /today, /incidents, /template <followup_ref>. Detail hanya untuk private user bound berizin. Binding: buat intent dari dashboard, /start token di private chat, lalu konfirmasikan di dashboard.', $binding);

                        return 'HELP';
                    }
                    abort_unless($binding, 403);
                    $actor = User::findOrFail($binding->user_id);
                    $visible = app(ProjectAccess::class)->query($actor, $org)->pluck('id');
                    $projects = [];
                    if ($payload['command'] === 'template') {
                        $f = ClientFollowup::forOrganization($org)->findOrFail($payload['followup_id']);
                        app(RenewalAccess::class)->require($actor, $org, $f->cycle->subscription, 'followup.manage');
                        $draft = app(DraftGenerator::class)->generate($org, $f, $f->cycle->state === 'verified' ? 'TPL-10' : null, $actor);
                        $text = $draft->draft_status === 'ready' ? $draft->rendered_body : 'Draft blocked: '.implode(', ', $draft->blocked_reasons)."\n".app(TelegramMessages::class)->link($org, OutboxEvent::make(['payload' => ['followup_id' => $f->id]]));
                        $projects = app(RenewalAccess::class)->projectIds($f->cycle->subscription)->all();
                        $context = ['followup_id' => $f->id, 'template_key' => $draft->template_key];
                    } elseif ($payload['command'] === 'today') {
                        $items = ClientFollowup::forOrganization($org)->whereNotIn('state', ['resolved', 'cancelled'])->get()->filter(fn ($f) => app(RenewalAccess::class)->projectIds($f->cycle->subscription)->isNotEmpty() && app(RenewalAccess::class)->projectIds($f->cycle->subscription)->diff($visible)->isEmpty());
                        $text = 'Follow-up dalam scope: '.$items->count()."\n".$items->sortBy(fn ($f) => array_search($f->severity, ['critical', 'warning', 'info']))->take(10)->map(fn ($f) => '#'.$f->id.' · '.strtoupper($f->severity).' · '.app(TelegramMessages::class)->clean($f->cycle->subscription->service_name).' · '.$f->state)->implode("\n")."\nDashboard: ".app(TelegramMessages::class)->link($org);
                        $projects = $items->flatMap(fn ($f) => app(RenewalAccess::class)->projectIds($f->cycle->subscription))->unique()->values()->all();
                        $context = [];
                    } else {
                        $items = Incident::forOrganization($org)->whereNotIn('state', ['resolved', 'closed'])->whereHas('projects', fn ($q) => $q->whereIn('projects.id', $visible))->get();
                        $text = 'Incident dalam scope: '.$items->count()."\n".$items->sortBy(fn ($i) => array_search($i->severity, ['critical', 'warning', 'info']))->take(10)->map(fn ($i) => '#'.$i->id.' · '.strtoupper($i->severity).' · '.$i->state.' · '.$i->projects()->whereIn('projects.id', $visible)->get()->map(fn ($p) => app(TelegramMessages::class)->clean($p->name))->implode(', '))->implode("\n")."\nDashboard: ".rtrim(config('app.url'), '/').'/organizations/'.$org->id.'/overview';
                        $projects = $items->flatMap(fn ($i) => $i->projects()->whereIn('projects.id', $visible)->pluck('projects.id'))->unique()->values()->all();
                        $context = [];
                    }
                    app(TelegramPrivateReply::class)->write($receipt, $payload, $text, $binding, $projects, $context);

                    return 'COMMAND_REPLIED';
                });
            } catch (HttpExceptionInterface|AuthorizationException|ModelNotFoundException|\Illuminate\Validation\ValidationException) {
                $code = 'ACTION_DENIED';
            }
            $receipt->update(['status' => 'processed', 'result_code' => $code, 'processed_at' => now('UTC')]);

            return $payload['kind'] === 'callback' ? [$bot, $payload['query_id'], $code] : null;
        });
        if ($answer) {
            try {
                app(TelegramTransport::class)->request($answer[0], 'answerCallbackQuery', ['callback_query_id' => $answer[1], 'text' => $answer[2] === 'CALLBACK_APPLIED' ? 'Tindakan dicatat; detail di private/dashboard.' : 'Permintaan ditolak, sudah diproses, atau stale. Buka dashboard.', 'show_alert' => false]);
            } catch (\Throwable) { /* No business retry or raw provider error from acknowledgement failure. */
            }
        }
    }
}
