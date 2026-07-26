<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            $table->ulid('scan_token')->nullable()->after('stable_id');
            $table->index(['media_source_id', 'scan_token']);
        });

        Schema::table('epg_programs', function (Blueprint $table): void {
            $table->ulid('sync_token')->nullable()->after('fingerprint');
            $table->index(['iptv_provider_id', 'sync_token']);
        });
    }

    public function down(): void
    {
        Schema::table('epg_programs', function (Blueprint $table): void {
            $table->dropIndex(['iptv_provider_id', 'sync_token']);
            $table->dropColumn('sync_token');
        });

        Schema::table('media_items', function (Blueprint $table): void {
            $table->dropIndex(['media_source_id', 'scan_token']);
            $table->dropColumn('scan_token');
        });
    }
};
