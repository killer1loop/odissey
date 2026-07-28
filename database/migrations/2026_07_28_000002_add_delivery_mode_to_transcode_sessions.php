<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcode_sessions', function (Blueprint $table): void {
            $table->string('delivery_mode', 24)
                ->default('fullTranscode')
                ->after('profile');
            $table->index([
                'user_id',
                'media_item_id',
                'delivery_mode',
                'created_at',
            ], 'transcode_user_media_mode_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('transcode_sessions', function (Blueprint $table): void {
            $table->dropIndex('transcode_user_media_mode_created_index');
            $table->dropColumn('delivery_mode');
        });
    }
};
