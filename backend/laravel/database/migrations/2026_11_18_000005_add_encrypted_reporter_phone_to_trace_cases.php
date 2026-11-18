<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('trace_cases', function (Blueprint $table) {
            $table->text('reporter_phone')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trace_cases', function (Blueprint $table) {
            $table->dropColumn('reporter_phone');
        });
    }
};
