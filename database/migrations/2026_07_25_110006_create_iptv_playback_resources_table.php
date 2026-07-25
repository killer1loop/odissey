<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iptv_playback_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('iptv_playback_session_id')
                ->constrained('iptv_playback_sessions')
                ->cascadeOnDelete();
            $table->foreignUuid('parent_resource_id')
                ->nullable()
                ->constrained('iptv_playback_resources')
                ->cascadeOnDelete();
            $table->string('upstream_fingerprint', 64);
            $table->longText('upstream_url');
            $table->string('resource_type')->default('unknown');
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('content_type')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(
                ['iptv_playback_session_id', 'upstream_fingerprint'],
                'iptv_resource_session_fingerprint_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iptv_playback_resources');
    }
};
