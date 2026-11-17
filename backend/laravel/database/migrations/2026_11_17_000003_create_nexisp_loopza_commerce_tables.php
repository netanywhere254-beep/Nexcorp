<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexisp_bandwidth_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedInteger('speed_mbps');
            $table->decimal('price', 12, 2);
            $table->char('currency', 3)->default('KES');
            $table->unsignedSmallInteger('validity_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('loopza_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 100);
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->char('currency', 3)->default('KES');
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->string('status', 24)->default('draft')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'sku']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('loopza_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('order_number', 64)->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->string('payment_status', 24)->default('unpaid')->index();
            $table->char('currency', 3);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'user_id', 'created_at']);
        });

        Schema::create('loopza_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loopza_order_id')->constrained('loopza_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('loopza_products')->nullOnDelete();
            $table->string('sku', 100);
            $table->string('title');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
            $table->index(['product_id']);
        });

        Schema::create('nexisp_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('bandwidth_plan_id')->nullable()
                ->constrained('nexisp_bandwidth_plans')->nullOnDelete();
            $table->string('subscription_number', 64)->unique();
            $table->string('status', 24)->default('pending_payment')->index();
            $table->decimal('price_amount', 12, 2);
            $table->char('currency', 3);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->json('plan_snapshot');
            $table->timestamps();
            $table->index(['organization_id', 'user_id', 'status']);
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->morphs('payable');
            $table->string('provider', 40);
            $table->string('provider_reference', 150)->nullable()->unique();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('status', 24)->default('initiated')->index();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->timestamp('verified_at')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('nexisp_subscriptions');
        Schema::dropIfExists('loopza_order_items');
        Schema::dropIfExists('loopza_orders');
        Schema::dropIfExists('loopza_products');
        Schema::dropIfExists('nexisp_bandwidth_plans');
    }
};
