<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->constrained('payment_transactions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number', 64)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('provider', 40);
            $table->string('provider_reference', 150)->nullable();
            $table->string('payable_type', 120);
            $table->unsignedBigInteger('payable_id');
            $table->timestamp('issued_at');
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->unique('payment_transaction_id');
            $table->index(['organization_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
