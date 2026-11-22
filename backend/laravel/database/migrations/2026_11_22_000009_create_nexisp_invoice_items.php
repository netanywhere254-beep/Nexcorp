<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nexisp_invoices', function (Blueprint $table) {
            $table->decimal('subtotal', 14, 2)->default(0)->after('amount');
            $table->decimal('tax_amount', 14, 2)->default(0)->after('subtotal');
            $table->string('notes', 1000)->nullable();
            $table->json('document_snapshot')->nullable();
        });

        Schema::create('nexisp_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('nexisp_invoices')->cascadeOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('tax_rate_percent', 7, 4)->default(0);
            $table->decimal('line_subtotal', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
            $table->foreign(['organization_id', 'invoice_id'], 'nexisp_invoice_item_tenant_fk')
                ->references(['organization_id', 'id'])->on('nexisp_invoices')->cascadeOnDelete();
            $table->index(['organization_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexisp_invoice_items');
        Schema::table('nexisp_invoices', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'tax_amount', 'notes', 'document_snapshot']);
        });
    }
};
