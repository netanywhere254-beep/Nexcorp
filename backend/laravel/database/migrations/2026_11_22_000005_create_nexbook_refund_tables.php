<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexbook_payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('nexbook_payments')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique()
                ->constrained('nexisp_journal_entries')->restrictOnDelete();
            $table->string('refund_number', 64);
            $table->string('status', 20)->default('draft');
            $table->date('refund_date');
            $table->char('currency', 3);
            $table->string('method', 24);
            $table->string('reference', 120)->nullable();
            $table->string('reason', 500);
            $table->decimal('amount', 18, 2);
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'refund_number']);
            $table->index(['organization_id', 'payment_id', 'status']);
        });

        Schema::create('nexbook_payment_refund_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained('nexbook_payment_refunds')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('nexbook_invoices')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->unique(['refund_id', 'invoice_id']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexbook_payment_refund_allocations');
        Schema::dropIfExists('nexbook_payment_refunds');
    }
};
