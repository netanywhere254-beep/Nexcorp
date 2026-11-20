<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_reference_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('system', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['system', 'year'], 'case_reference_system_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_reference_sequences');
    }
};
