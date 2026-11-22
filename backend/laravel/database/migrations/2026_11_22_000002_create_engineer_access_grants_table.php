<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineer_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('scope')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'status', 'revoked_at']);
            $table->index(['granted_by_user_id', 'created_at']);
            $table->index(['approved_by_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineer_access_grants');
    }
};
