<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_change_inbox', function (Blueprint $table) {
            $table->unique('idempotency_key', 'client_inbox_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('client_change_inbox', function (Blueprint $table) {
            $table->dropUnique('client_inbox_idempotency_unique');
        });
    }
};
