<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_feature_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('product_key', 32);
            $table->string('feature_key', 64);
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'feature_key']);
            $table->index(['organization_id', 'product_key', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feature_entitlements');
    }
};
