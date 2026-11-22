<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nexisp_journal_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('reversal_of_entry_id')->nullable()->after('source_id');
            $table->unique(['organization_id', 'reversal_of_entry_id'], 'nexisp_journal_reversal_unique');
            $table->foreign(['organization_id', 'reversal_of_entry_id'], 'nexisp_journal_reversal_tenant_fk')
                ->references(['organization_id', 'id'])->on('nexisp_journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nexisp_journal_entries', function (Blueprint $table) {
            $table->dropForeign('nexisp_journal_reversal_tenant_fk');
            $table->dropUnique('nexisp_journal_reversal_unique');
            $table->dropColumn('reversal_of_entry_id');
        });
    }
};
