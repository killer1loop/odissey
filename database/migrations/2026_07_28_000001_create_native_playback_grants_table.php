<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('native_playback_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('native_client_session_id')
                ->constrained('native_client_sessions')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('resource_type', 16);
            $table->string('resource_id', 64);
            $table->string('delivery_mode', 24);
            $table->string('playback_reference', 64)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('native_playback_grants');
    }
};
