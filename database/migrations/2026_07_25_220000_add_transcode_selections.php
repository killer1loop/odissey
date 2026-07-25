<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcode_sessions', function (Blueprint $table): void {
            $table->string('profile', 16)->default('auto');
            $table->unsignedSmallInteger('audio_track')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transcode_sessions', fn (Blueprint $table) => $table->dropColumn(['profile', 'audio_track']));
    }
};
