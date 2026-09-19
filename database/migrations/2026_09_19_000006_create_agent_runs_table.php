<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catatan audit setiap kali AI Agent memproses sebuah pesan.
     * unique(trigger_message_id) memastikan satu pesan masuk hanya diproses AI satu kali.
     */
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trigger_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('model')->nullable();
            $table->string('status', 16)->index();
            $table->json('actions')->nullable();
            $table->json('usage')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique('trigger_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
