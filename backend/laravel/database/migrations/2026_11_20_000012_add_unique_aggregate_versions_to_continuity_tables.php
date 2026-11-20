<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_change_outbox', function (Blueprint $table) {
            $table->unique(
                ['organization_id', 'product_code', 'aggregate_type', 'aggregate_id', 'aggregate_version'],
                'client_outbox_aggregate_version_unique'
            );
        });

        Schema::table('client_change_inbox', function (Blueprint $table) {
            $table->unique(
                ['organization_id', 'product_code', 'aggregate_type', 'aggregate_id', 'aggregate_version'],
                'client_inbox_aggregate_version_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('client_change_inbox', function (Blueprint $table) {
            $table->dropUnique('client_inbox_aggregate_version_unique');
        });

        Schema::table('client_change_outbox', function (Blueprint $table) {
            $table->dropUnique('client_outbox_aggregate_version_unique');
        });
    }
};
