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
        Schema::create('dns_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('cloudflare_id')->nullable();
            $table->string('type', 10);
            $table->string('name');
            $table->text('content');
            $table->unsignedInteger('ttl')->default(1);
            $table->boolean('proxied')->default(false);
            $table->unsignedInteger('priority')->nullable();
            $table->timestamps();

            $table->unique(['domain_id', 'cloudflare_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dns_records');
    }
};
