<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('trace_cases', function (Blueprint $table) {
            // TraceCase encrypts reporter_phone at the model layer, so this
            // must be a TEXT column rather than a short VARCHAR.
            $table->text('reporter_phone')->nullable()->after('reporter_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('trace_cases', function (Blueprint $table) {
            $table->dropColumn('reporter_phone');
        });
    }
};
