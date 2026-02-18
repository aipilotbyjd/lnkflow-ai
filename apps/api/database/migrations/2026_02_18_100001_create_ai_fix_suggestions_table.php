<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_fix_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('failed_node_key', 100);
            $table->text('error_message');
            $table->text('diagnosis');
            $table->json('suggestions');
            $table->smallInteger('applied_index')->nullable();
            $table->string('model_used', 50);
            $table->unsignedInteger('tokens_used')->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index('workspace_id');
            $table->index('execution_id');
            $table->index('workflow_id');
        });

        Schema::table('workspace_policies', function (Blueprint $table) {
            $table->boolean('ai_auto_fix_enabled')->default(false)->after('redaction_rules');
            $table->decimal('ai_auto_fix_confidence_threshold', 3, 2)->default(0.95)->after('ai_auto_fix_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_policies', function (Blueprint $table) {
            $table->dropColumn(['ai_auto_fix_enabled', 'ai_auto_fix_confidence_threshold']);
        });

        Schema::dropIfExists('ai_fix_suggestions');
    }
};
