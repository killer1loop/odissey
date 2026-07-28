<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('native_client_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('installation_id_hash', 64);
            $table->char('access_token_hash', 64)->unique();
            $table->char('refresh_token_hash', 64)->unique();
            $table->char('previous_refresh_token_hash', 64)->nullable();
            $table->string('device_name', 100);
            $table->string('platform', 32);
            $table->string('app_version', 32);
            $table->string('os_version', 32)->nullable();
            $table->timestamp('access_expires_at')->index();
            $table->timestamp('refresh_expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'installation_id_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('native_client_sessions');
    }
};
