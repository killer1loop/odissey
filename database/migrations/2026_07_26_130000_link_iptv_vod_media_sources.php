<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_sources', function (Blueprint $table): void {
            $table->foreignId('iptv_provider_id')
                ->nullable()
                ->unique()
                ->after('id')
                ->constrained('iptv_providers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_sources', function (Blueprint $table): void {
            $table->dropForeign(['iptv_provider_id']);
            $table->dropUnique(['iptv_provider_id']);
            $table->dropColumn('iptv_provider_id');
        });
    }
};
