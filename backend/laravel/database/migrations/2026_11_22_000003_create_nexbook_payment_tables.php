<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexbook_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique()
                ->constrained('nexisp_journal_entries')->restrictOnDelete();
            $table->string('receipt_number', 64);
            $table->string('status', 20)->default('draft');
            $table->date('payment_date');
            $table->char('currency', 3)->default('KES');
            $table->string('method', 24);
            $table->string('reference', 120)->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('allocated_amount', 18, 2)->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'receipt_number']);
            $table->index(['organization_id', 'payment_date', 'status']);
        });

        Schema::create('nexbook_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('nexbook_payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('nexbook_invoices')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->unique(['payment_id', 'invoice_id']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexbook_payment_allocations');
        Schema::dropIfExists('nexbook_payments');
    }
};
