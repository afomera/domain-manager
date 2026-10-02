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
            $table->string('sync_batch_id')->nullable()->after('sync_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cloudflare_connections', function (Blueprint $table) {
            $table->dropColumn('sync_batch_id');
        });
    }
};
