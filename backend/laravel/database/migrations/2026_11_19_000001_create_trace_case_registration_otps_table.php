<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('trace_case_registration_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 32);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_sent_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_case_registration_otps');
    }
};
