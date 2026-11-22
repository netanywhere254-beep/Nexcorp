<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexbook_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('nexbook_invoices')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique()
                ->constrained('nexisp_journal_entries')->restrictOnDelete();
            $table->string('credit_note_number', 64);
            $table->string('status', 20)->default('issued');
            $table->string('reason', 500);
            $table->date('credit_date');
            $table->char('currency', 3);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'credit_note_number']);
            $table->index(['organization_id', 'invoice_id', 'status']);
        });

        Schema::create('nexbook_credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('nexbook_credit_notes')->cascadeOnDelete();
            $table->foreignId('invoice_line_id')->constrained('nexbook_invoice_lines')->restrictOnDelete();
            $table->foreignId('revenue_account_id')->constrained('nexisp_accounts')->restrictOnDelete();
            $table->string('description', 255);
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->index(['credit_note_id', 'invoice_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexbook_credit_note_lines');
        Schema::dropIfExists('nexbook_credit_notes');
    }
};
