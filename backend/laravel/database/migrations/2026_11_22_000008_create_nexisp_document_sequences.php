<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexisp_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 20);
            $table->string('period', 8);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'document_type', 'period'], 'nexisp_document_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexisp_document_sequences');
    }
};
