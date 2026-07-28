<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('native_refresh_token_uses', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('native_client_session_id')
                ->constrained('native_client_sessions')
                ->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('used_at')->index();

            $table->index([
                'native_client_session_id',
                'used_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('native_refresh_token_uses');
    }
};
