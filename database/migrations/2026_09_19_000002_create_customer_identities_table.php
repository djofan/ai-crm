<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identitas customer per channel (nomor WhatsApp, id Telegram, email, dst).
     * Dipakai untuk mengenali customer dari pesan masuk tanpa mengubah tabel customers
     * setiap kali channel baru ditambahkan.
     */
    public function up(): void
    {
        Schema::create('customer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('identifier', 191);
            $table->string('display_name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identities');
    }
};
