<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_followups', function (Blueprint $table) {
            $table->json('template_context')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('template_key', 20);
            $table->string('locale')->default('id');
            $table->unsignedInteger('published_version')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'template_key']);
        });
        Schema::create('message_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('body');
            $table->json('mandatory_variables');
            $table->json('allowed_variables');
            $table->string('trigger');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['message_template_id', 'version'], 'template_version_unique');
        });
        Schema::create('template_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_followup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_template_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('template_key', 20);
            $table->unsignedInteger('template_version');
            $table->unsignedInteger('source_entity_version');
            $table->char('source_hash', 64);
            $table->json('variables_snapshot');
            $table->text('rendered_body');
            $table->string('draft_status', 30);
            $table->json('blocked_reasons');
            $table->timestamp('generated_at');
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['client_followup_id', 'message_template_version_id', 'source_hash'], 'current_draft_unique');
        });
        Schema::table('contact_attempts', function (Blueprint $table) {
            $table->foreign('template_draft_id')->references('id')->on('template_drafts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contact_attempts', fn (Blueprint $table) => $table->dropForeign(['template_draft_id']));
        Schema::dropIfExists('template_drafts');
        Schema::dropIfExists('message_template_versions');
        Schema::dropIfExists('message_templates');
        Schema::table('client_followups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
            $table->dropColumn('template_context');
        });
    }
};
