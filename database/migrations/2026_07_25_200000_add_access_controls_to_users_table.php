<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('disabled_at')->nullable();
        });

        Schema::create('installation_states', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        $now = now();

        // Never guess which legacy account should become an administrator.
        // Existing installations recover explicitly with
        // `odissey:user:promote-admin`, while anonymous setup stays closed.
        DB::table('installation_states')->insert([
            'key' => 'initial_setup',
            'completed_at' => DB::table('users')->exists() ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_states');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_admin']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['is_admin', 'is_active', 'disabled_at']);
        });
    }
};
