<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_staff_approved_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone_e164', 16)->unique();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });

        Schema::create('group_staff_otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approved_phone_id')->constrained('group_staff_approved_phones')->restrictOnDelete();
            $table->string('purpose', 100);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_staff_otp_challenges');
        Schema::dropIfExists('group_staff_approved_phones');
    }
};
