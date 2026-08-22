<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            // Catalog pages sort and filter by kind + title; without this
            // index every page request sorts the full filtered library.
            $table->index(['media_kind', 'title', 'id'], 'media_items_kind_title_id_index');
        });

        Schema::table('playback_history', function (Blueprint $table): void {
            // Retention pruning scans by played_at alone; the existing user
            // composite indexes cannot drive that query.
            $table->index(['played_at', 'id'], 'playback_history_played_at_id_index');
        });

        Schema::table('iptv_playback_attempts', function (Blueprint $table): void {
            $table->index('iptv_playback_session_id', 'iptv_playback_attempts_session_index');
        });

        Schema::table('iptv_playback_resources', function (Blueprint $table): void {
            $table->index('parent_resource_id', 'iptv_playback_resources_parent_index');
        });

        Schema::table('transcode_sessions', function (Blueprint $table): void {
            $table->index('media_subtitle_id', 'transcode_sessions_subtitle_index');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            $table->dropIndex('media_items_kind_title_id_index');
        });

        Schema::table('playback_history', function (Blueprint $table): void {
            $table->dropIndex('playback_history_played_at_id_index');
        });

        Schema::table('iptv_playback_attempts', function (Blueprint $table): void {
            $table->dropIndex('iptv_playback_attempts_session_index');
        });

        Schema::table('iptv_playback_resources', function (Blueprint $table): void {
            $table->dropIndex('iptv_playback_resources_parent_index');
        });

        Schema::table('transcode_sessions', function (Blueprint $table): void {
            $table->dropIndex('transcode_sessions_subtitle_index');
        });
    }
};
