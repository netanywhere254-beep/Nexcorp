<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineer_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('idle_expires_at')->index();
            $table->timestamp('absolute_expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revocation_reason', 48)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'actor_user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineer_sessions');
    }
};
