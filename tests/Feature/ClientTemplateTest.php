<?php

namespace Tests\Feature;

use App\Application\ClientTemplates\DraftGenerator;
use App\Application\ClientTemplates\PlaintextRenderer;
use App\Application\ClientTemplates\TemplateCatalog;
use App\Application\ClientTemplates\TemplateGovernance;
use App\Application\RenewalFollowups\RenewalScheduler;
use App\Domain\IdentityAccess\Role;
use App\Models\Asset;
use App\Models\ClientFollowup;
use App\Models\ContactAttempt;
use App\Models\Evidence;
use App\Models\Membership;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\TemplateDraft;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\RenewalFixture;
use Tests\TestCase;

class ClientTemplateTest extends TestCase
{
    use RefreshDatabase, RenewalFixture;

    public function test_ten_versioned_seeds_are_valid_deterministic_and_idempotent(): void
    {
        [$org] = $this->renewalGraph();
        app(TemplateGovernance::class)->seed($org);
        app(TemplateGovernance::class)->seed($org);
        $this->assertSame(10, MessageTemplate::count());
        $this->assertSame(10, MessageTemplateVersion::count());
        foreach (app(TemplateCatalog::class)->definitions() as $definition) {
            $vars = array_fill_keys(TemplateCatalog::VARIABLES, 'Contoh');
            $vars['provider_name'] = null;
            $vars['action_deadline_display'] = null;
            $a = app(PlaintextRenderer::class)->render($definition['body'], $vars, $definition['mandatory_variables']);
            $this->assertSame('ready', $a['status']);
            $this->assertSame($a, app(PlaintextRenderer::class)->render($definition['body'], $vars, $definition['mandatory_variables']));
            $this->assertStringNotContainsString('{{', $a['body']);
            $this->assertStringNotContainsString('paling lambat', $a['body']);
        }
    }

    public function test_threshold_atomically_creates_current_draft_and_repeat_does_not_duplicate(): void
    {
        [$org, $owner, $service] = $this->renewalGraph();
        $now = CarbonImmutable::parse('2026-10-04T03:00:00Z');
        app(RenewalScheduler::class)->tick($org, $now);
        $draft = TemplateDraft::firstOrFail();
        $this->assertSame('TPL-01', $draft->template_key);
        $this->assertSame('ready', $draft->draft_status);
        $this->assertStringContainsString('09-10-2026 (jam tidak diketahui)', $draft->rendered_body);
        $this->assertStringNotContainsString('paling lambat', $draft->rendered_body);
        app(RenewalScheduler::class)->tick($org, $now);
        $again = app(DraftGenerator::class)->generate($org, ClientFollowup::firstOrFail(), actor: $owner, now: $now);
        $this->assertSame($draft->id, $again->id);
        $this->assertSame(1, TemplateDraft::count());
    }

    public function test_every_template_mandatory_variable_blocks_a_missing_value(): void
    {
        foreach (app(TemplateCatalog::class)->definitions() as $key => $definition) {
            foreach ($definition['mandatory_variables'] as $mandatory) {
                $variables = array_fill_keys(TemplateCatalog::VARIABLES, 'Fictitious valid value');
                $variables[$mandatory] = null;
                $result = app(PlaintextRenderer::class)->render($definition['body'], $variables, $definition['mandatory_variables']);
                $this->assertSame('blocked_missing_data', $result['status'], $key.': '.$mandatory);
                $this->assertContains($mandatory, $result['missing']);
            }
        }
    }

    public function test_unknown_and_missing_contact_are_honest_and_cannot_fake_verified_closure(): void
    {
        [$org, $owner, $service, $project, $contact] = $this->renewalGraph(['date_precision' => 'unknown', 'expiry_date' => null, 'source_timezone' => null]);
        app(RenewalScheduler::class)->tick($org);
        $draft = TemplateDraft::firstOrFail();
        $this->assertSame('TPL-04', $draft->template_key);
        $this->assertSame('ready', $draft->draft_status);
        $this->assertStringNotContainsString('berakhir pada', $draft->rendered_body);
        $contact->update(['contact_value' => null]);
        $blocked = app(DraftGenerator::class)->generate($org, ClientFollowup::firstOrFail());
        $this->assertSame('blocked_missing_data', $blocked->draft_status);
        $this->assertContains('contact_target', $blocked->blocked_reasons);
        $this->assertSame('stale', $draft->fresh()->draft_status);
        $closed = app(DraftGenerator::class)->generate($org, ClientFollowup::firstOrFail(), 'TPL-10', $owner);
        $this->assertContains('verified_renewal_required', $closed->blocked_reasons);
    }

    public function test_publish_supersedes_draft_without_replacing_historical_sent_body(): void
    {
        [$org, $owner, $service, $project, $contact] = $this->renewalGraph();
        app(RenewalScheduler::class)->tick($org, CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        $draft = TemplateDraft::firstOrFail();
        $attempt = ContactAttempt::create(['organization_id' => $org->id, 'client_followup_id' => $draft->client_followup_id, 'actor_user_id' => $owner->id, 'contact_id' => $contact->id, 'sent_at' => now(), 'recorded_at' => now(), 'manual_channel' => 'manual', 'template_draft_id' => $draft->id, 'draft_version' => $draft->template_version, 'sent_body' => $draft->rendered_body]);
        $body = app(TemplateCatalog::class)->definitions()['TPL-01']['body']."\n\nSalam hangat.";
        $version = app(TemplateGovernance::class)->publish($org, $owner, 'TPL-01', 1, $body);
        $this->assertSame(2, $version->version);
        $this->assertSame('superseded', $draft->fresh()->draft_status);
        $fresh = app(DraftGenerator::class)->generate($org, ClientFollowup::firstOrFail(), actor: $owner, now: CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        $this->assertSame(2, $fresh->template_version);
        $this->assertSame($draft->rendered_body, $attempt->fresh()->sent_body);
        $this->assertStringContainsString('Salam hangat', $fresh->rendered_body);
        Membership::where('user_id', $owner->id)->update(['role' => Role::Operator->value]);
        try {
            app(TemplateGovernance::class)->publish($org, $owner, 'TPL-01', 2, $body);
            $this->fail('Operator published wording.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_invalid_syntax_unknown_variables_and_sensitive_client_text_are_rejected(): void
    {
        [$org, $owner] = $this->renewalGraph();
        foreach (['{{unknown_variable}}', '{{client_name}', '[[if client_name]]unclosed', '[[endif]]', '[[if client_name]][[if service_name]]nested[[endif]][[endif]]', 'URL https://opshub.example.test/internal', 'token=private-fixture-secret', 'CVE-2026-12345'] as $body) {
            try {
                app(TemplateGovernance::class)->publish($org, $owner, 'TPL-01', 1, $body);
                $this->fail('Unsafe syntax/text accepted: '.$body);
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $render = app(PlaintextRenderer::class)->render('Halo {{client_name}}. [[if provider_name]]Provider {{provider_name}}.[[endif]]', ['client_name' => '<b>Ibu 😀 & Co</b>', 'provider_name' => null], ['client_name']);
        $this->assertSame('Halo Ibu 😀 & Co.', $render['body']);
        $this->assertSame('ready', $render['status']);
    }

    public function test_review_templates_require_verified_scoped_context_and_preview_is_labelled(): void
    {
        [$org, $owner] = $this->renewalGraph();
        app(RenewalScheduler::class)->tick($org, CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        $followup = ClientFollowup::firstOrFail();
        $blocked = app(DraftGenerator::class)->generate($org, $followup, 'TPL-06', $owner);
        $this->assertContains('reviewed_evidence_required', $blocked->blocked_reasons);
        $evidence = Evidence::create(['organization_id' => $org->id, 'kind' => 'approved_secure_access', 'source' => 'fixture_review', 'verified_by_user_id' => $owner->id, 'verified_at' => now()]);
        $ready = app(DraftGenerator::class)->generate($org, $followup, 'TPL-06', $owner, ['requested_action' => 'pemeriksaan yang disetujui', 'safe_access_instruction' => 'undangan akun terbatas pada panel provider melalui jalur aman yang disepakati', 'review_evidence_id' => $evidence->id]);
        $this->assertSame('ready', $ready->draft_status);
        $preview = app(TemplateGovernance::class)->preview($org, $owner, 'TPL-04', app(TemplateCatalog::class)->definitions()['TPL-04']['body'], []);
        $this->assertSame('preview', $preview['mode']);
        $this->assertSame('blocked_missing_data', $preview['status']);
    }

    public function test_unsafe_registry_value_blocks_draft_without_copying_secret_into_snapshot(): void
    {
        [$org, $owner, $service, $project, $contact] = $this->renewalGraph();
        $contact->update(['name' => 'Bearer fictitious-secret-for-test']);
        app(RenewalScheduler::class)->tick($org, CarbonImmutable::parse('2026-10-04T03:00:00Z'));
        $draft = TemplateDraft::firstOrFail();
        $this->assertSame('blocked_missing_data', $draft->draft_status);
        $this->assertContains('unsafe_source_contact_salutation', $draft->blocked_reasons);
        $this->assertStringNotContainsString('fictitious-secret-for-test', json_encode($draft->toArray()));
    }

    public function test_owner_http_preview_publish_enforce_syntax_version_and_role(): void
    {
        [$org, $owner] = $this->renewalGraph();
        $url = '/api/v1/organizations/'.$org->id.'/client-templates';
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(10, 'data');
        $this->postJson($url.'/TPL-04/preview', ['body' => 'Halo {{client_name}}', 'variables' => []])->assertOk()->assertJsonPath('data.mode', 'preview')->assertJsonPath('data.status', 'blocked_missing_data');
        $this->postJson($url.'/TPL-04/publish', ['body' => '{{unknown}}', 'version' => 1])->assertUnprocessable();
        $body = app(TemplateCatalog::class)->definitions()['TPL-04']['body'];
        $this->postJson($url.'/TPL-04/publish', ['body' => $body, 'version' => 1])->assertCreated()->assertJsonPath('data.version', 2);
        $this->postJson($url.'/TPL-04/publish', ['body' => $body, 'version' => 1])->assertConflict();
        Membership::where('user_id', $owner->id)->update(['role' => Role::Operator->value]);
        $this->getJson($url)->assertForbidden();
        $this->postJson($url.'/TPL-04/publish', ['body' => $body, 'version' => 2])->assertForbidden();
    }

    public function test_domain_template_requires_actual_canonical_domain_resource(): void
    {
        [$org, $owner, $service] = $this->renewalGraph(['service_kind' => 'domain']);
        $now = CarbonImmutable::parse('2026-10-04T03:00:00Z');
        app(RenewalScheduler::class)->tick($org, $now);
        $draft = TemplateDraft::firstOrFail();
        $this->assertSame('TPL-02', $draft->template_key);
        $this->assertContains('domain_name', $draft->blocked_reasons);
        $asset = Asset::create(['organization_id' => $org->id, 'kind' => 'domain', 'canonical_identity' => 'domain-fixture.test']);
        $service->update(['resource_asset_id' => $asset->id, 'version' => $service->version + 1]);
        $fresh = app(DraftGenerator::class)->generate($org, ClientFollowup::firstOrFail(), actor: $owner, now: $now);
        $this->assertSame('ready', $fresh->draft_status);
        $this->assertStringContainsString('domain-fixture.test', $fresh->rendered_body);
        $this->assertStringNotContainsString('fixture-hosting', $fresh->rendered_body);
    }
}
