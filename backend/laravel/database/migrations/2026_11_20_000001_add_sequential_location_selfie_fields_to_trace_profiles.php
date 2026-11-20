<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trace_profiles', function (Blueprint $table) {
            $table->timestamp('location_consent_at')->nullable();
            $table->timestamp('location_submitted_at')->nullable();
            $table->timestamp('biometric_processing_consent_at')->nullable();
            $table->timestamp('selfie_submitted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trace_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'location_consent_at',
                'location_submitted_at',
                'biometric_processing_consent_at',
                'selfie_submitted_at',
            ]);
        });
    }
};
