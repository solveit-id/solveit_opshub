<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('email_verified_at');
            $table->timestamp('mfa_enabled_at')->nullable()->after('is_active');
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('timezone')->default('Asia/Jakarta');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->json('extra_permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('purpose')->nullable();
            $table->string('preferred_manual_channel')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'client_id']);
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('lifecycle')->default('draft');
            $table->string('criticality')->default('normal');
            $table->foreignId('internal_pic_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('stack_tags')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'lifecycle']);
        });

        Schema::create('environments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('display_name');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['project_id', 'display_name']);
            $table->index(['organization_id', 'project_id']);
        });

        Schema::create('evidences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('secure_reference')->nullable();
            $table->string('content_digest')->nullable();
            $table->string('source')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'kind']);
        });

        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('canonical_identity');
            $table->string('responsibility')->default('solveit');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->nullable();
            $table->foreignId('evidence_id')->nullable()->constrained('evidences')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'kind', 'canonical_identity']);
        });

        Schema::create('asset_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose');
            $table->timestamps();
            $table->unique(['asset_id', 'project_id', 'environment_id', 'purpose'], 'asset_usage_unique');
        });

        Schema::create('hosting_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->nullable();
            $table->string('panel_type')->nullable();
            $table->string('hostname')->nullable();
            $table->string('account_identifier')->nullable();
            $table->unsignedBigInteger('quota_bytes')->nullable();
            $table->json('available_access')->nullable();
            $table->json('environment_roots')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'asset_id']);
        });

        Schema::create('service_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->timestamp('billing_due_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('date_precision')->default('unknown');
            $table->string('source_timezone')->nullable();
            $table->string('paying_party')->nullable();
            $table->string('action_owner')->nullable();
            $table->foreignId('evidence_id')->nullable()->constrained('evidences')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'expires_at']);
        });

        Schema::create('management_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type');
            $table->unsignedBigInteger('resource_id');
            $table->json('allowed_action_classes');
            $table->foreignId('evidence_id')->nullable()->constrained('evidences')->nullOnDelete();
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'resource_type', 'resource_id']);
        });

        Schema::create('secret_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('external_locator');
            $table->string('type');
            $table->string('version')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'external_locator']);
        });

        Schema::create('policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind');
            $table->string('status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('configuration');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['policy_id', 'version']);
        });

        Schema::create('policy_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_version_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type');
            $table->unsignedBigInteger('resource_id');
            $table->json('overrides')->nullable();
            $table->timestamps();
            $table->unique(['policy_version_id', 'resource_type', 'resource_id']);
        });

        Schema::create('job_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type')->default('organization');
            $table->unsignedBigInteger('resource_id')->default(0);
            $table->string('kind');
            $table->timestamp('scheduled_slot');
            $table->unsignedBigInteger('policy_version_id')->default(0);
            $table->string('state')->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('lease_owner')->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'resource_type', 'resource_id', 'kind', 'scheduled_slot', 'policy_version_id'], 'job_runs_slot_unique');
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->unsignedInteger('aggregate_version');
            $table->json('payload')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('available_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('correlation_id')->nullable();
            $table->uuid('causation_id')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'event_type', 'aggregate_type', 'aggregate_id', 'aggregate_version'], 'outbox_aggregate_version_unique');
            $table->index(['status', 'available_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action');
            $table->string('key');
            $table->string('request_hash');
            $table->json('response')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['organization_id', 'actor_user_id', 'action', 'key'], 'idempotency_scope_unique');
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type')->default('human');
            $table->string('action');
            $table->string('object_type');
            $table->string('object_id');
            $table->json('permission_context')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->string('outcome');
            $table->uuid('request_id')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'object_type', 'object_id']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('job_runs');
        Schema::dropIfExists('policy_assignments');
        Schema::dropIfExists('policy_versions');
        Schema::dropIfExists('policies');
        Schema::dropIfExists('secret_references');
        Schema::dropIfExists('management_authorizations');
        Schema::dropIfExists('service_subscriptions');
        Schema::dropIfExists('hosting_accounts');
        Schema::dropIfExists('asset_usages');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('evidences');
        Schema::dropIfExists('environments');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('organizations');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_active', 'mfa_enabled_at']);
        });
    }
};
