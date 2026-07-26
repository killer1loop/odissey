<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->string('logo_source', 32)
                ->nullable()
                ->after('stream_icon');
            $table->string('logo_channel_id')
                ->nullable()
                ->after('logo_source');
            $table->index(['logo_source', 'logo_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropIndex(['logo_source', 'logo_channel_id']);
            $table->dropColumn(['logo_source', 'logo_channel_id']);
        });
    }
};
