<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexbook_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('nexisp_subscriptions')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique()
                ->constrained('nexisp_journal_entries')->restrictOnDelete();
            $table->string('invoice_number', 64);
            $table->string('status', 20)->default('draft');
            $table->string('customer_name', 160);
            $table->string('customer_email', 190)->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('KES');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('amount_paid', 18, 2)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'invoice_number']);
            $table->index(['organization_id', 'status', 'due_date']);
            $table->index(['organization_id', 'customer_user_id', 'issue_date']);
        });

        Schema::create('nexbook_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('nexbook_invoices')->cascadeOnDelete();
            $table->foreignId('revenue_account_id')->constrained('nexisp_accounts')->restrictOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
            $table->index(['invoice_id', 'revenue_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexbook_invoice_lines');
        Schema::dropIfExists('nexbook_invoices');
    }
};
