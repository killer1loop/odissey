<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('iptv_providers')
            ->where('enabled', true)
            ->update([
                'sync_status' => 'pending',
                'last_error_code' => 'provider_catalog_upgrade_required',
            ]);
    }

    public function down(): void
    {
        DB::table('iptv_providers')
            ->where(
                'last_error_code',
                'provider_catalog_upgrade_required',
            )
            ->update([
                'sync_status' => 'pending',
                'last_error_code' => null,
            ]);
    }
};
