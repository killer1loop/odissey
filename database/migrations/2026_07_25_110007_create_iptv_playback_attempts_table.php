<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iptv_playback_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('iptv_playback_session_id')
                ->constrained('iptv_playback_sessions')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('outcome');
            $table->unsignedSmallInteger('upstream_status')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iptv_playback_attempts');
    }
};
