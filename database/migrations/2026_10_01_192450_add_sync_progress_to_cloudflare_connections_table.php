<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cloudflare_connections', function (Blueprint $table) {
            $table->string('sync_status')->nullable()->after('last_sync_error');
            $table->json('sync_progress')->nullable()->after('sync_status');
            $table->timestamp('sync_started_at')->nullable()->after('sync_progress');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cloudflare_connections', function (Blueprint $table) {
            $table->dropColumn(['sync_status', 'sync_progress', 'sync_started_at']);
        });
    }
};
