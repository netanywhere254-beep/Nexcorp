<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_authorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('authority', 48);
            $table->string('scope_type', 32)->default('group');
            $table->string('scope_key', 100)->default('nexora');
            $table->string('status', 24)->default('active')->index();
            $table->foreignId('appointed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['scope_type', 'scope_key', 'authority', 'status'], 'group_authority_scope_idx');
            $table->index(['user_id', 'status']);
        });

        Schema::create('governance_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->string('requested_authority', 48);
            $table->string('scope_type', 32)->default('group');
            $table->string('scope_key', 100)->default('nexora');
            $table->string('status', 24)->default('pending')->index();
            $table->text('business_reason');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['subject_user_id', 'status']);
            $table->index(['scope_type', 'scope_key', 'status']);
        });

        Schema::create('governance_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governance_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('approval_level');
            $table->string('required_authority', 48);
            $table->string('decision', 24)->default('pending')->index();
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['governance_request_id', 'approval_level'], 'governance_request_level_unique');
            $table->index(['approver_user_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_approvals');
        Schema::dropIfExists('governance_requests');
        Schema::dropIfExists('group_authorities');
    }
};
