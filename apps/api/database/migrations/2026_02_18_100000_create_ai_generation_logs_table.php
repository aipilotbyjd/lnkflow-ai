<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->json('generated_json');
            $table->string('model_used', 50);
            $table->unsignedInteger('tokens_used')->default(0);
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generation_logs');
    }
};
