<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('source_type', 32);
            $table->text('source_locator');
            $table->string('mime_type', 128)->nullable();
            $table->string('container', 32)->nullable();
            $table->string('video_codec', 32)->nullable();
            $table->string('audio_codec', 32)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->boolean('requires_transcode')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'source_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
