<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playback_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('media_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('position_ms')->default(0);
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->boolean('completed')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'media_item_id']);
        });

        Schema::create('playback_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('media_item_id')->constrained()->cascadeOnDelete();
            $table->string('event', 24);
            $table->unsignedBigInteger('position_ms')->default(0);
            $table->timestamp('played_at');
            $table->timestamps();

            $table->index(['user_id', 'played_at']);
            $table->index(['user_id', 'media_item_id', 'played_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_history');
        Schema::dropIfExists('playback_progress');
    }
};
