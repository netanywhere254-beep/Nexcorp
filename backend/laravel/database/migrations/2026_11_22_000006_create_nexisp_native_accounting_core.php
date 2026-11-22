<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nexisp_invoices', function (Blueprint $table) {
            $table->unique(['organization_id', 'id'], 'nexisp_invoice_tenant_id_unique');
        });

        Schema::create('nexisp_chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('account_type', 24);
            $table->string('normal_balance', 8);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('system_account')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'parent_id'])
                ->references(['organization_id', 'id'])->on('nexisp_chart_of_accounts')->restrictOnDelete();
            $table->index(['organization_id', 'account_type', 'active']);
        });

        Schema::create('nexisp_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('entry_number', 64);
            $table->string('status', 16)->default('draft');
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 255);
            $table->char('currency', 3);
            $table->date('entry_date');
            $table->decimal('total_debit', 14, 2)->default(0);
            $table->decimal('total_credit', 14, 2)->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'entry_number']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'entry_date', 'status']);
            $table->index(['organization_id', 'source_type', 'source_id']);
        });

        Schema::create('nexisp_journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->constrained('nexisp_journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('nexisp_chart_of_accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->timestamps();
            $table->foreign(['organization_id', 'journal_entry_id'], 'nexisp_journal_line_entry_tenant_fk')
                ->references(['organization_id', 'id'])->on('nexisp_journal_entries')->cascadeOnDelete();
            $table->foreign(['organization_id', 'account_id'], 'nexisp_journal_line_account_tenant_fk')
                ->references(['organization_id', 'id'])->on('nexisp_chart_of_accounts')->restrictOnDelete();
            $table->index(['organization_id', 'account_id']);
        });

        Schema::create('nexisp_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('expense_number', 64);
            $table->foreignId('expense_account_id')->constrained('nexisp_chart_of_accounts')->restrictOnDelete();
            $table->string('vendor_name', 160)->nullable();
            $table->string('description', 255);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('status', 20)->default('draft');
            $table->date('expense_date');
            $table->string('reference', 120)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('nexisp_journal_entries')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'expense_number']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'expense_date', 'status']);
            $table->foreign(['organization_id', 'expense_account_id'], 'nexisp_expense_account_tenant_fk')
                ->references(['organization_id', 'id'])->on('nexisp_chart_of_accounts')->restrictOnDelete();
        });

        Schema::create('nexisp_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('nexisp_invoices')->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->string('receipt_number', 64);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('payment_method', 40)->nullable();
            $table->string('payment_reference', 150)->nullable();
            $table->timestamp('issued_at');
            $table->json('snapshot');
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'receipt_number']);
            $table->unique(['organization_id', 'invoice_id', 'payment_transaction_id'], 'nexisp_receipt_payment_unique');
            $table->foreign(['organization_id', 'invoice_id'], 'nexisp_receipt_invoice_tenant_fk')
                ->references(['organization_id', 'id'])->on('nexisp_invoices')->restrictOnDelete();
            $table->index(['organization_id', 'issued_at']);
        });

        Schema::create('nexisp_tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 100);
            $table->decimal('rate_percent', 7, 4);
            $table->boolean('inclusive')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'active']);
        });

        Schema::create('nexisp_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained('nexisp_chart_of_accounts')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('provider', 40)->nullable();
            $table->string('account_reference', 120)->nullable();
            $table->char('currency', 3);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'ledger_account_id'], 'nexisp_bank_ledger_tenant_fk')
                ->references(['organization_id', 'id'])->on('nexisp_chart_of_accounts')->restrictOnDelete();
        });

        Schema::create('nexisp_bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('nexisp_bank_accounts')->cascadeOnDelete();
            $table->string('external_reference', 150)->nullable();
            $table->date('transaction_date');
            $table->string('description', 255);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('direction', 8);
            $table->string('reconciliation_status', 20)->default('unmatched');
            $table->foreignId('matched_journal_entry_id')->nullable()->constrained('nexisp_journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'bank_account_id', 'external_reference'], 'nexisp_bank_external_ref_unique');
            $table->foreign(['organization_id', 'bank_account_id'], 'nexisp_bank_tx_account_tenant_fk')
                ->references(['organization_id', 'id'])->on('nexisp_bank_accounts')->cascadeOnDelete();
            $table->index(['organization_id', 'transaction_date', 'reconciliation_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexisp_bank_transactions');
        Schema::dropIfExists('nexisp_bank_accounts');
        Schema::dropIfExists('nexisp_tax_rates');
        Schema::dropIfExists('nexisp_receipts');
        Schema::dropIfExists('nexisp_expenses');
        Schema::dropIfExists('nexisp_journal_entry_lines');
        Schema::dropIfExists('nexisp_journal_entries');
        Schema::dropIfExists('nexisp_chart_of_accounts');
        Schema::table('nexisp_invoices', function (Blueprint $table) {
            $table->dropUnique('nexisp_invoice_tenant_id_unique');
        });
    }
};
