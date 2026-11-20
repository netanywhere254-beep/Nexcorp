<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexora_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('case_reference', 40)->unique();
            $table->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
            $table->string('case_type', 32)->index();
            $table->text('title');
            $table->longText('description');
            $table->string('priority', 16)->default('normal')->index();
            $table->string('status', 24)->default('submitted')->index();
            $table->string('visibility', 16)->default('private')->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['reporter_user_id', 'created_at'], 'nexora_cases_reporter_created_idx');
            $table->index(['status', 'priority', 'created_at'], 'nexora_cases_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexora_cases');
    }
};
