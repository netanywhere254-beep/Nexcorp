<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexisp_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_number', 64)->unique();
            $table->string('name');
            $table->text('phone');
            $table->char('phone_hash', 64);
            $table->string('status', 24)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'phone_hash']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status']);
        });

        // Composite keys ensure a customer can only be attached to a subscription
        // belonging to the same organization.
        Schema::table('nexisp_subscriptions', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')
                ->constrained('nexisp_customers')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::create('nexisp_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('nexisp_customers')->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()
                ->constrained('nexisp_subscriptions')->nullOnDelete();
            $table->string('invoice_number', 64)->unique();
            $table->string('status', 24)->default('open')->index();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->timestamp('issued_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'customer_id', 'status']);
            $table->foreign(['organization_id', 'customer_id'], 'nexisp_invoices_customer_tenant_fk')
                ->references(['organization_id', 'id'])->on('nexisp_customers');
            $table->foreign(['organization_id', 'subscription_id'], 'nexisp_invoices_subscription_tenant_fk')
                ->references(['organization_id', 'id'])->on('nexisp_subscriptions');
        });

        Schema::create('payment_callbacks', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('external_id', 150)->nullable();
            $table->char('payload_hash', 64);
            $table->longText('payload');
            $table->string('status', 24)->default('received')->index();
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'payload_hash']);
            $table->index(['provider', 'external_id']);
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->dropUnique(['idempotency_key']);
            $table->unique(['organization_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('nexisp_invoices', function (Blueprint $table) {
            $table->dropForeign('nexisp_invoices_customer_tenant_fk');
            $table->dropForeign('nexisp_invoices_subscription_tenant_fk');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'idempotency_key']);
            $table->unique(['idempotency_key']);
            // Rollback requires customer-only payments to be reassigned to a user first.
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('nexisp_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::dropIfExists('payment_callbacks');
        Schema::dropIfExists('nexisp_invoices');
        Schema::dropIfExists('nexisp_customers');
    }
};
