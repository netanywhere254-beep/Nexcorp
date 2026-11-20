<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_change_inbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->string('idempotency_key', 150)->nullable();
            $table->char('event_hash', 64);
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product_code', 32);
            $table->string('aggregate_type', 100);
            $table->string('aggregate_id', 120);
            $table->string('event_type', 120);
            $table->unsignedBigInteger('aggregate_version');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['processed_at', 'id'], 'client_inbox_processing_idx');
            $table->index(['product_code', 'aggregate_type', 'aggregate_id'], 'client_inbox_aggregate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_change_inbox');
    }
};
