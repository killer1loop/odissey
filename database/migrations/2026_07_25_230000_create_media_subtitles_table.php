<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_subtitles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('media_item_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_id', 255);
            $table->string('language', 16);
            $table->string('label', 255);
            $table->longText('path');
            $table->boolean('hearing_impaired')->default(false);
            $table->longText('metadata')->nullable();
            $table->timestamps();
            $table->unique(['media_item_id', 'provider', 'external_id']);
            $table->index(['media_item_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_subtitles');
    }
};
