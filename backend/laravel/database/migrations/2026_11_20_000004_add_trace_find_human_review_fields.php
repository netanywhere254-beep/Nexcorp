<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trace_profiles', function (Blueprint $table) {
            $table->string('identity_verification_status', 24)->default('pending')->after('verification_status')->index();
            $table->foreignId('reviewed_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_notes')->nullable()->after('reviewed_at');
        });

        Schema::table('trace_cases', function (Blueprint $table) {
            $table->text('review_notes')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('trace_cases', function (Blueprint $table) {
            $table->dropColumn('review_notes');
        });

        Schema::table('trace_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'identity_verification_status',
                'reviewed_at',
                'review_notes',
            ]);
        });
    }
};
