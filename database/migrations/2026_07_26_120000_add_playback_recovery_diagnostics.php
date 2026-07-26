<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iptv_playback_sessions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('consecutive_failure_count')
                ->default(0)
                ->after('attempt_count');
            $table->timestamp('last_failure_at')
                ->nullable()
                ->after('last_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('iptv_playback_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'consecutive_failure_count',
                'last_failure_at',
            ]);
        });
    }
};
