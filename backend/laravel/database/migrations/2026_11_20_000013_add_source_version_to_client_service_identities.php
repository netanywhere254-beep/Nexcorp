<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_service_identities', function (Blueprint $table) {
            $table->unsignedBigInteger('source_version')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('client_service_identities', function (Blueprint $table) {
            $table->dropColumn('source_version');
        });
    }
};
