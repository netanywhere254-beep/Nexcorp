<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexisp_fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'status', 'starts_on']);
        });

        Schema::create('nexisp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('nexisp_accounts')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('type', 24);
            $table->string('normal_balance', 6);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'type', 'is_active']);
        });

        Schema::create('nexisp_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_period_id')->nullable()
                ->constrained('nexisp_fiscal_periods')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entry_number', 48);
            $table->date('entry_date');
            $table->string('description', 500);
            $table->string('source_type', 64)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->string('currency', 3)->default('KES');
            $table->string('status', 16)->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'entry_number']);
            $table->unique(['organization_id', 'source_type', 'source_id']);
            $table->index(['organization_id', 'entry_date', 'status']);
        });

        Schema::create('nexisp_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('nexisp_journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('nexisp_accounts')->restrictOnDelete();
            $table->string('memo', 255)->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->timestamps();
            $table->index(['account_id', 'journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexisp_journal_lines');
        Schema::dropIfExists('nexisp_journal_entries');
        Schema::dropIfExists('nexisp_accounts');
        Schema::dropIfExists('nexisp_fiscal_periods');
    }
};
