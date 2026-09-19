<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('direction', 16);
            $table->string('sender_type', 16);
            $table->string('type', 16)->default('text');
            $table->text('content')->nullable();
            // ID pesan dari provider (mis. wamid). Nullable untuk pesan yang dibuat manual/internal.
            // MySQL & SQLite mengizinkan banyak NULL pada unique index.
            $table->string('external_message_id', 191)->nullable();
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Dasar idempotency: satu pesan provider hanya boleh tersimpan sekali per channel.
            $table->unique(['channel', 'external_message_id']);
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
