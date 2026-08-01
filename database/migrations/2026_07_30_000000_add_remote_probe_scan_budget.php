<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_sources', function (Blueprint $table): void {
            $table->unsignedInteger('scan_probe_jobs')
                ->default(0)
                ->after('scan_caption_jobs');
        });
    }

    public function down(): void
    {
        Schema::table('media_sources', function (Blueprint $table): void {
            $table->dropColumn('scan_probe_jobs');
        });
    }
};
