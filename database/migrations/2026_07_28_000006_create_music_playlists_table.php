<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_playlists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->timestamps();

            $table->unique(['user_id', 'normalized_name']);
        });

        Schema::create(
            'music_playlist_items',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('music_playlist_id')
                    ->constrained('music_playlists')
                    ->cascadeOnDelete();
                $table->foreignUlid('media_item_id')
                    ->constrained('media_items')
                    ->cascadeOnDelete();
                $table->unsignedInteger('position');
                $table->timestamps();

                $table->unique([
                    'music_playlist_id',
                    'position',
                ]);
                $table->unique([
                    'music_playlist_id',
                    'media_item_id',
                ]);
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('music_playlist_items');
        Schema::dropIfExists('music_playlists');
    }
};
