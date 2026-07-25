<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iptv_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->longText('base_url');
            $table->longText('username');
            $table->longText('password');
            $table->longText('config')->nullable();
            $table->boolean('allow_insecure_http')->default(false);
            $table->boolean('enabled')->default(true);
            $table->string('sync_status')->default('pending');
            $table->string('last_error_code')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_guide_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iptv_providers');
    }
};
