<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('usage_period_id')->constrained('workspace_usage_periods')->cascadeOnDelete();
            $table->enum('type', ['execution', 'ai_execution', 'code_execution', 'webhook', 'refund', 'adjustment', 'credit_pack', 'bonus']);
            $table->integer('credits');
            $table->string('description')->nullable();
            $table->foreignId('execution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('execution_node_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['workspace_id', 'created_at']);
            $table->index(['usage_period_id', 'type']);
            $table->index('execution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
