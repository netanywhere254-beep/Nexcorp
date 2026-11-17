<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('product_key', 40);
            $table->string('feature', 80);
            $table->string('provider', 80);
            $table->string('model', 120);
            $table->string('status', 24);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'product_key', 'created_at'], 'ai_usage_tenant_product_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
