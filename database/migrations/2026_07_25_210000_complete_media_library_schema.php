<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('type', 24);
            $table->longText('configuration');
            $table->longText('capabilities')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('allow_private_network')->default(false);
            $table->string('scan_status', 24)->default('pending');
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'type']);
        });

        Schema::table('media_items', function (Blueprint $table): void {
            $table->foreignUlid('media_source_id')->nullable()->after('user_id')
                ->constrained('media_sources')->cascadeOnDelete();
            $table->string('stable_id', 64)->nullable()->after('media_source_id');
            $table->text('relative_path')->nullable()->after('source_locator');
            $table->string('media_kind', 16)->default('video')->after('title');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('source_modified_at')->nullable();
            $table->timestamp('missing_at')->nullable();
            $table->longText('metadata')->nullable();

            $table->unique(['media_source_id', 'stable_id']);
            $table->index(['media_source_id', 'media_kind', 'missing_at']);
        });

        Schema::create('media_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('media_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'media_item_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('timezone', 64)->default('UTC');
            $table->longText('preferences')->nullable();
        });

        Schema::table('playback_history', function (Blueprint $table): void {
            $table->unsignedBigInteger('watched_ms')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('playback_history', fn (Blueprint $table) => $table->dropColumn('watched_ms'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['timezone', 'preferences']));
        Schema::dropIfExists('media_favorites');
        Schema::table('media_items', function (Blueprint $table): void {
            $table->dropForeign(['media_source_id']);
            $table->dropUnique(['media_source_id', 'stable_id']);
            $table->dropIndex(['media_source_id', 'media_kind', 'missing_at']);
            $table->dropColumn([
                'media_source_id', 'stable_id', 'relative_path', 'media_kind',
                'size_bytes', 'source_modified_at', 'missing_at', 'metadata',
            ]);
        });
        Schema::dropIfExists('media_sources');
    }
};
