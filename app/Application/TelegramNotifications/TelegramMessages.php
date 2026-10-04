<?php

namespace App\Application\TelegramNotifications;

use App\Application\ClientTemplates\DraftGenerator;
use App\Application\RenewalFollowups\RenewalAccess;
use App\Infrastructure\Security\SensitiveDataRedactor;
use App\Models\ClientFollowup;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use Carbon\CarbonImmutable;
use LogicException;

class TelegramMessages
{
    public function render(OutboxEvent $event, TelegramDestination $dest): array
    {
        $org = Organization::findOrFail($event->organization_id);
        $scope = $dest->all_projects ? Project::forOrganization($org)->pluck('id')->all() : $dest->project_ids;
        $ids = array_values(array_intersect($event->payload['impacted_project_ids'] ?? [], $scope));
        $projects = Project::forOrganization($org)->whereIn('id', $ids)->get();
        $severity = $event->payload['severity'] ?? 'info';
        $fake = TelegramBot::findOrFail($dest->telegram_bot_id)->identity_fake;
        $when = CarbonImmutable::parse($event->created_at)->setTimezone('Asia/Jakarta')->format('d-m-Y H:i').' WIB';
        $link = $this->link($org, $event);
        $resource = $event->aggregate_type.' #'.$event->aggregate_id;
        $owner = 'Solveit';
        $next = 'Buka dashboard, tinjau evidence dan catat tindakan.';
        $evidence = $this->clean($event->event_type);
        $followup = isset($event->payload['followup_id']) ? ClientFollowup::forOrganization($org)->find($event->payload['followup_id']) : null;
        if ($followup) {
            $service = $followup->cycle->subscription;
            $resource = $this->clean($service->service_name ?: 'Layanan #'.$service->id);
            $owner = $this->clean($service->action_owner);
            $evidence = $service->date_precision === 'unknown' ? 'Expiry unknown; verifikasi diperlukan.' : 'Expiry tercatat: '.($service->expiry_date?->format('d-m-Y') ?? $service->expires_at?->toIso8601String()).' · '.$service->source;
            $next = 'Review current draft, hubungi contact secara manual, lalu catat pengiriman.';
        }
        if ($event->aggregate_type === 'incident') {
            $incident = Incident::forOrganization($org)->find($event->aggregate_id);
            $evidence = $incident ? $this->clean($incident->reason_code).' · '.$incident->state : 'Incident sudah tidak tersedia.';
        }
        if ($event->event_type === 'telegram.test_requested') {
            $resource = 'Test destination '.$this->clean($dest->label);
            $evidence = 'Test eksplisit Owner, tanpa data client atau secret.';
            $next = 'Periksa delivery dashboard; sent bukan read receipt.';
        }
        $text = ($fake ? "[FAKE TESTING]\n" : '').strtoupper($severity).' · '.$this->clean($event->event_type)."\nEvent: ".$event->event_id."\nProject: ".$projects->take(10)->map(fn ($p) => $this->clean($p->name))->implode(', ').($projects->count() > 10 ? ' +'.($projects->count() - 10).' proyek dalam scope' : '')."\nResource: ".$resource."\nWaktu: ".$when."\nEvidence: ".$this->clean($evidence)."\nAction owner: ".$owner."\nPIC: ".$projects->take(10)->map(fn ($p) => $p->internal_pic_user_id ? 'user #'.$p->internal_pic_user_id : 'belum ditugaskan')->unique()->implode(', ')."\nTindakan: ".$next."\nDashboard: ".$link;
        if ($followup?->next_followup_at) {
            $text .= "\nDeadline: ".$followup->next_followup_at->setTimezone('Asia/Jakarta')->format('d-m-Y H:i').' WIB';
        }
        $clientParts = [];
        if ($followup && in_array($followup->cycle->subscription->action_owner, ['client', 'shared'], true)) {
            $draft = app(DraftGenerator::class)->generate($org, $followup, $event->event_type === 'renewal.verified' ? 'TPL-10' : null);
            $full = app(RenewalAccess::class)->projectIds($followup->cycle->subscription)->diff($scope)->isEmpty();
            if ($draft->draft_status === 'ready' && $full) {
                $clientParts = $this->segment($draft->rendered_body);
                if (count($clientParts) > 8) {
                    $clientParts = [];
                    $text .= "\nTemplate panjang: review/copy naskah lengkap di dashboard.";
                }
            } else {
                $text .= "\nDraft blocked: ".($full ? implode(', ', $draft->blocked_reasons) : 'template_scope_incomplete').'. Lengkapi/review di dashboard.';
            }
        }

        $messages = [];
        $buttons = app(TelegramCallbacks::class)->buttons($event, $dest);
        foreach ($this->segment($text) as $part) {
            $messages[] = ['kind' => 'internal', 'text' => $part, 'reply_markup' => ['inline_keyboard' => array_values(array_filter([[['text' => 'Buka dashboard', 'url' => $link]], $buttons]))], 'project_ids' => $ids];
        }
        foreach ($clientParts as $part) {
            $messages[] = ['kind' => 'client_template', 'text' => $part, 'reply_markup' => null, 'project_ids' => $ids];
        }

        return $messages;
    }

    public function clean(string $text): string
    {
        $text = app(SensitiveDataRedactor::class)->redact($text);
        $text = preg_replace('/https?:\/\/\S+|-----BEGIN.*|\b(?:password|secret|token|api[_-]?key)\s*[:=]\s*\S+|\b\d{6,}:[A-Za-z0-9_-]{20,}/i', '[REDACTED]', $text);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($text));

        return mb_substr(trim($text), 0, 250);
    }

    public function link(Organization $org, ?OutboxEvent $event = null): string
    {
        $base = rtrim(config('app.url'), '/');
        $p = parse_url($base);
        if (strlen($base) > 255 || ! isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || ! in_array($p['scheme'], ['http', 'https'], true) || (! app()->environment(['local', 'testing']) && $p['scheme'] !== 'https')) {
            throw new LogicException('Authenticated dashboard URL is not configured safely.');
        }
        $path = $event && isset($event->payload['followup_id']) ? '/follow-ups/'.$event->payload['followup_id'] : ($event?->aggregate_type === 'incident' ? '/incidents/'.$event->aggregate_id : '/renewals');

        return $base.'/organizations/'.$org->id.$path;
    }

    public function units(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    public function segment(string $text): array
    {
        $chunks = [];
        $current = '';
        foreach (preg_split('/(\n)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $paragraph) {
            if ($this->units($current.$paragraph) <= 3300) {
                $current .= $paragraph;

                continue;
            }
            if ($current !== '') {
                $chunks[] = trim($current);
                $current = '';
            }
            if ($this->units($paragraph) <= 3300) {
                $current = $paragraph;

                continue;
            }
            $units = 0;
            foreach (preg_split('//u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) as $char) {
                $size = $this->units($char);
                if ($units + $size > 3300) {
                    $chunks[] = trim($current);
                    $current = '';
                    $units = 0;
                }
                $current .= $char;
                $units += $size;
            }
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }
        $count = count($chunks);

        return $count > 1 ? array_map(fn ($part, $i) => '['.($i + 1).'/'.$count."]\n".$part, $chunks, array_keys($chunks)) : $chunks;
    }
}
