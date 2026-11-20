<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trace_country_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_application_id')->constrained('trace_access_applications')->restrictOnDelete();
            $table->string('access_role', 40);
            $table->char('country_code', 2);
            $table->string('region_code', 64)->nullable();
            $table->json('permissions');
            $table->string('status', 24)->default('active');
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'country_code', 'status'], 'trace_country_access_user_idx');
            $table->index(['country_code', 'region_code', 'status'], 'trace_country_access_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_country_access_grants');
    }
};
