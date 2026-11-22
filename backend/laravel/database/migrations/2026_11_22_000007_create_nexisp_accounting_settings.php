<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexisp_accounting_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('operating_mode', 20)->default('native');
            $table->char('base_currency', 3)->default('KES');
            $table->string('fiscal_year_start', 5)->default('01-01');
            $table->string('invoice_prefix', 16)->default('INV');
            $table->string('receipt_prefix', 16)->default('RCT');
            $table->string('expense_prefix', 16)->default('EXP');
            $table->boolean('native_ledger_enabled')->default(true);
            $table->boolean('expenses_enabled')->default(true);
            $table->boolean('tax_enabled')->default(true);
            $table->boolean('bank_reconciliation_enabled')->default(true);
            $table->boolean('budgets_enabled')->default(true);
            $table->boolean('recurring_invoices_enabled')->default(true);
            $table->boolean('external_sync_enabled')->default(false);
            $table->json('feature_preferences')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexisp_accounting_settings');
    }
};
