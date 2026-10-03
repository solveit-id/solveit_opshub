<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosting_accounts', function (Blueprint $table): void {
            $table->string('api_endpoint')->nullable()->after('hostname');
        });

        Schema::table('service_subscriptions', function (Blueprint $table): void {
            $table->string('billing_party')->nullable()->after('asset_id');
            $table->string('source')->nullable()->after('source_timezone');
            $table->json('reminder_policy')->nullable()->after('evidence_id');
        });

        Schema::table('management_authorizations', function (Blueprint $table): void {
            $table->string('authorizer_label')->nullable()->after('allowed_action_classes');
        });
    }

    public function down(): void
    {
        Schema::table('management_authorizations', function (Blueprint $table): void {
            $table->dropColumn('authorizer_label');
        });

        Schema::table('service_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['billing_party', 'source', 'reminder_policy']);
        });

        Schema::table('hosting_accounts', function (Blueprint $table): void {
            $table->dropColumn('api_endpoint');
        });
    }
};
