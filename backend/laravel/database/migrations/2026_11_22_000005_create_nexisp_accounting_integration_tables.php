<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 40);
            $table->string('display_name', 120);
            $table->string('external_account_id', 160)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->longText('access_token')->nullable();
            $table->longText('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('granted_scopes')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'provider', 'external_account_id'], 'accounting_connection_account_unique');
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('accounting_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_connection_id')->constrained('accounting_connections')->cascadeOnDelete();
            $table->string('mapping_type', 40);
            $table->string('local_code', 80);
            $table->string('external_account_id', 160);
            $table->string('external_account_name', 160)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['accounting_connection_id', 'mapping_type', 'local_code'], 'accounting_account_mapping_unique');
            $table->foreign(['organization_id', 'accounting_connection_id'], 'accounting_mapping_connection_tenant_fk')
                ->references(['organization_id', 'id'])->on('accounting_connections')->cascadeOnDelete();
        });

        Schema::create('accounting_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_connection_id')->constrained('accounting_connections')->restrictOnDelete();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 16)->default('export');
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_succeeded')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->foreign(['organization_id', 'accounting_connection_id'], 'accounting_run_connection_tenant_fk')
                ->references(['organization_id', 'id'])->on('accounting_connections')->restrictOnDelete();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('accounting_sync_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_connection_id')->constrained('accounting_connections')->restrictOnDelete();
            $table->foreignId('accounting_sync_run_id')->nullable()->constrained('accounting_sync_runs')->nullOnDelete();
            $table->string('local_type', 80);
            $table->unsignedBigInteger('local_id');
            $table->string('operation', 24)->default('upsert');
            $table->string('idempotency_key', 190);
            $table->char('payload_hash', 64);
            $table->string('external_id', 190)->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'accounting_connection_id', 'idempotency_key'], 'accounting_sync_item_idempotency_unique');
            $table->foreign(['organization_id', 'accounting_connection_id'], 'accounting_item_connection_tenant_fk')
                ->references(['organization_id', 'id'])->on('accounting_connections')->restrictOnDelete();
            $table->index(['organization_id', 'local_type', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_sync_items');
        Schema::dropIfExists('accounting_sync_runs');
        Schema::dropIfExists('accounting_account_mappings');
        Schema::dropIfExists('accounting_connections');
    }
};
