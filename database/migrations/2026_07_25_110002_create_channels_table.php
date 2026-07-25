<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iptv_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id');
            $table->string('epg_channel_id')->nullable()->index();
            $table->string('name');
            $table->string('channel_number')->nullable();
            $table->longText('stream_icon')->nullable();
            $table->string('stream_extension', 16)->default('m3u8');
            $table->longText('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['iptv_provider_id', 'external_id']);
            $table->index(['channel_group_id', 'is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
