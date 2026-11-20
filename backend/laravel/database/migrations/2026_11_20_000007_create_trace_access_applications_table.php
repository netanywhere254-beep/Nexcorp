<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trace_access_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('requested_role', 40);
            $table->char('country_code', 2);
            $table->string('organisation_name', 180)->nullable();
            $table->string('organisation_registration', 120)->nullable();
            $table->text('credentials_summary')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('status', 32)->default('pending_review');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['country_code', 'requested_role', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_access_applications');
    }
};