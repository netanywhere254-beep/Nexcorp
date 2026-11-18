<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_staff_approved_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone_e164', 16);
            $table->string('status', 16)->default('active')->index();
            $table->foreignId('approved_by')->constrained('users');
            $table->timestamp('approved_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->unique(['organization_id', 'phone_e164']);
        });

        Schema::create('organization_staff_otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approved_phone_id')->constrained('organization_staff_approved_phones')->cascadeOnDelete();
            $table->string('purpose', 100);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'user_id', 'purpose', 'created_at'], 'staff_otp_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_staff_otp_challenges');
        Schema::dropIfExists('organization_staff_approved_phones');
    }
};
