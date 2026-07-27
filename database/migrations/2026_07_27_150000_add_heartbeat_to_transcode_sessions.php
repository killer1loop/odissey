<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcode_sessions', function (Blueprint $table): void {
            $table->timestamp('heartbeat_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('transcode_sessions', function (Blueprint $table): void {
            $table->dropIndex(['heartbeat_at']);
            $table->dropColumn('heartbeat_at');
        });
    }
};
