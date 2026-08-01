<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iptv_providers', function (Blueprint $table): void {
            $table->string('last_guide_error_code')
                ->nullable()
                ->after('last_error_code');
        });

        DB::table('iptv_providers')
            ->whereIn('last_error_code', [
                'guide_not_configured',
                'guide_sync_failed',
                'provider_invalid_guide',
                'xmltv_guide_empty',
                'xmltv_guide_truncated',
                'xmltv_invalid',
                'xmltv_unavailable',
            ])
            ->update([
                'last_guide_error_code' => DB::raw('last_error_code'),
                'last_error_code' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('iptv_providers')
            ->whereNull('last_error_code')
            ->whereNotNull('last_guide_error_code')
            ->update([
                'last_error_code' => DB::raw('last_guide_error_code'),
            ]);

        Schema::table('iptv_providers', function (Blueprint $table): void {
            $table->dropColumn('last_guide_error_code');
        });
    }
};
