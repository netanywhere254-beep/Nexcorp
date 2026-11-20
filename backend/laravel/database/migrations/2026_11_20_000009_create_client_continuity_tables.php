<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_service_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('product_code', 32);
            $table->string('external_reference', 120);
            $table->string('status', 24)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'product_code', 'external_reference'], 'client_service_external_ref_unique');
            $table->unique(['organization_id', 'user_id', 'product_code'], 'client_service_user_product_unique');
            $table->index(['user_id', 'status']);
        });

        Schema::create('client_change_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->string('idempotency_key', 150)->nullable()->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_code', 32);
            $table->string('aggregate_type', 100);
            $table->string('aggregate_id', 120);
            $table->string('event_type', 120);
            $table->unsignedBigInteger('aggregate_version')->default(1);
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['published_at', 'id'], 'client_outbox_dispatch_idx');
            $table->index(['organization_id', 'product_code', 'aggregate_type', 'aggregate_id'], 'client_outbox_aggregate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_change_outbox');
        Schema::dropIfExists('client_service_identities');
    }
};
