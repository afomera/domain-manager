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
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('registrar');
            $table->string('registrar_name')->nullable();
            $table->string('registration_status')->nullable();
            $table->string('nameserver_provider');
            $table->json('nameservers')->nullable();
            $table->date('registered_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->unsignedInteger('renewal_price_cents')->nullable();
            $table->string('note')->nullable();
            $table->string('cloudflare_account_id')->nullable();
            $table->string('cloudflare_zone_id')->nullable();
            $table->string('cloudflare_zone_status')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
