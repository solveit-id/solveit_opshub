<?php

namespace App\Application\ClientTemplates;

use App\Application\ActivityEvidence\AuditWriter;
use App\Application\IdentityAccess\OrganizationAuthorizationService;
use App\Domain\IdentityAccess\Role;
use App\Models\Membership;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\Organization;
use App\Models\TemplateDraft;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TemplateGovernance
{
    public function seed(Organization $org): void
    {
        DB::transaction(function () use ($org) {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            foreach (app(TemplateCatalog::class)->definitions() as $key => $definition) {
                $template = MessageTemplate::firstOrCreate(['organization_id' => $org->id, 'template_key' => $key]);
                MessageTemplateVersion::firstOrCreate(['message_template_id' => $template->id, 'version' => 1], ['organization_id' => $org->id, ...$definition, 'published_at' => now('UTC')]);
            }
        });
    }

    public function preview(Organization $org, User $actor, string $key, string $body, array $variables): array
    {
        $this->requireOwner($org, $actor);
        $definition = app(TemplateCatalog::class)->definitions()[$key] ?? null;
        abort_unless($definition, 422, 'Template key invalid.');

        return ['mode' => 'preview', ...app(PlaintextRenderer::class)->render($body, $variables, $definition['mandatory_variables'])];
    }

    public function publish(Organization $org, User $actor, string $key, int $version, string $body): MessageTemplateVersion
    {
        $this->requireOwner($org, $actor);
        $definition = app(TemplateCatalog::class)->definitions()[$key] ?? null;
        abort_unless($definition, 422);
        app(PlaintextRenderer::class)->validate($body);
        $this->seed($org);

        return DB::transaction(function () use ($org, $actor, $key, $version, $body, $definition) {
            $template = MessageTemplate::forOrganization($org)->where('template_key', $key)->lockForUpdate()->firstOrFail();
            abort_unless($template->version === $version, 409);
            $next = $template->published_version + 1;
            $published = MessageTemplateVersion::create(['organization_id' => $org->id, 'message_template_id' => $template->id, ...$definition, 'body' => $body, 'version' => $next, 'published_at' => now('UTC'), 'published_by_user_id' => $actor->id]);
            $template->update(['published_version' => $next, 'version' => $template->version + 1]);
            TemplateDraft::forOrganization($org)->where('template_key', $key)->whereIn('draft_status', ['ready', 'blocked_missing_data', 'stale'])->update(['draft_status' => 'superseded']);
            app(AuditWriter::class)->write($org, 'template.published', 'MessageTemplateVersion', $published->id, 'success', $actor, after: ['key' => $key, 'version' => $next]);

            return $published;
        });
    }

    public function requireOwner(Organization $org, User $actor): void
    {
        app(OrganizationAuthorizationService::class)->require($actor, $org, 'organization.read');
        abort_unless(Membership::where('organization_id', $org->id)->where('user_id', $actor->id)->where('is_active', true)->where('role', Role::Owner->value)->exists(), 403);
    }
}
