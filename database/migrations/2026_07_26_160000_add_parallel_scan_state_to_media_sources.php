<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_sources', function (Blueprint $table): void {
            $table->string('active_scan_token', 26)
                ->nullable()
                ->after('scan_status')
                ->index();
            $table->boolean('scan_discovery_complete')
                ->default(false)
                ->after('active_scan_token');
            $table->unsignedInteger('scan_discovered')
                ->default(0)
                ->after('scan_discovery_complete');
            $table->unsignedInteger('scan_processed')
                ->default(0)
                ->after('scan_discovered');
            $table->unsignedInteger('scan_failed')
                ->default(0)
                ->after('scan_processed');
            $table->unsignedInteger('scan_caption_jobs')
                ->default(0)
                ->after('scan_failed');
        });

        DB::table('media_sources')
            ->where('enabled', true)
            ->whereIn('type', ['local', 's3', 'webdav'])
            ->update([
                'scan_status' => 'failed',
                'active_scan_token' => null,
                'last_error_code' => 'source_scan_upgrade_required',
            ]);
    }

    public function down(): void
    {
        Schema::table('media_sources', function (Blueprint $table): void {
            $table->dropIndex(['active_scan_token']);
            $table->dropColumn([
                'active_scan_token',
                'scan_discovery_complete',
                'scan_discovered',
                'scan_processed',
                'scan_failed',
                'scan_caption_jobs',
            ]);
        });
    }
};
