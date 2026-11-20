<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trace_case_resubmissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trace_case_id')->constrained('trace_cases')->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->json('previous_review');
            $table->timestamps();
            $table->index(['trace_case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_case_resubmissions');
    }
};
