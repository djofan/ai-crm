<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('new');
            $table->string('source', 32)->nullable();
            $table->string('title')->nullable();
            $table->string('interest')->nullable();
            $table->decimal('estimated_value', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('next_follow_up_at')->nullable()->index();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
