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
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->uuid('uuid')->unique();
            $table->string('path', 100)->nullable();

            $table->json('methods')->default('["POST"]');
            $table->boolean('is_active')->default(true);

            $table->enum('auth_type', ['none', 'header', 'basic', 'bearer'])->default('none');
            $table->text('auth_config')->nullable();

            $table->unsignedInteger('rate_limit')->nullable();

            $table->enum('response_mode', ['immediate', 'wait'])->default('immediate');
            $table->unsignedSmallInteger('response_status')->default(200);
            $table->json('response_body')->nullable();

            $table->unsignedBigInteger('call_count')->default(0);
            $table->timestamp('last_called_at')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
