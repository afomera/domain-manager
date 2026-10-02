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
            $table->date('token_expires_on')->nullable()->after('token_hint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cloudflare_connections', function (Blueprint $table) {
            $table->dropColumn('token_expires_on');
        });
    }
};
