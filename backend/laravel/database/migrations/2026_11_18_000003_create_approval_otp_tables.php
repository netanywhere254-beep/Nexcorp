<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorized_approval_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('phone_hash', 64)->unique();
            $table->text('phone_e164');
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'verified_at'], 'approval_phone_user_status_idx');
        });

        Schema::create('approval_otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governance_approval_id')->constrained()->cascadeOnDelete();
            $table->foreignId('authorized_approval_phone_id')->constrained()->restrictOnDelete();
            $table->foreignId('approver_user_id')->constrained('users')->restrictOnDelete();
            $table->string('otp_hash', 255);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['governance_approval_id', 'consumed_at']);
            $table->index(['approver_user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_otp_challenges');
        Schema::dropIfExists('authorized_approval_phones');
    }
};
