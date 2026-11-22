<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nexisp_invoices', function (Blueprint $table) {
            $table->dropUnique('nexisp_invoices_invoice_number_unique');
            $table->unique(['organization_id', 'invoice_number'], 'nexisp_invoice_number_tenant_unique');
        });
    }

    public function down(): void
    {
        Schema::table('nexisp_invoices', function (Blueprint $table) {
            $table->dropUnique('nexisp_invoice_number_tenant_unique');
            $table->unique('invoice_number');
        });
    }
};
