<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcode_sessions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('subtitle_track')
                ->nullable()
                ->after('audio_track');
            $table->foreignUlid('media_subtitle_id')
                ->nullable()
                ->after('subtitle_track')
                ->constrained('media_subtitles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transcode_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('media_subtitle_id');
            $table->dropColumn('subtitle_track');
        });
    }
};
