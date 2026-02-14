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
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon', 50)->default('workflow');
            $table->string('color', 20)->default('#6366f1');

            $table->boolean('is_active')->default(false);
            $table->boolean('is_locked')->default(false);

            $table->string('trigger_type', 20);
            $table->json('trigger_config')->nullable();

            $table->json('nodes');
            $table->json('edges');
            $table->json('viewport')->nullable();
            $table->json('settings')->nullable();

            $table->unsignedInteger('execution_count')->default(0);
            $table->timestamp('last_executed_at')->nullable();
            $table->decimal('success_rate', 5, 2)->default(0.00);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'is_active']);
            $table->index('trigger_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
