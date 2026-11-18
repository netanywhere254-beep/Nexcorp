<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_staff_approved_phones', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->change();
            $table->string('approval_method', 32)->default('staff_approval')->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('group_staff_approved_phones', function (Blueprint $table) {
            $table->dropColumn('approval_method');
            $table->foreignId('approved_by')->nullable(false)->change();
        });
    }
};
