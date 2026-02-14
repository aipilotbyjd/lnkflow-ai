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
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('node_categories')->cascadeOnDelete();

            $table->string('type', 100)->unique();
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('icon', 50);
            $table->string('color', 20);

            $table->string('node_kind', 20);

            $table->json('config_schema');
            $table->json('output_schema')->nullable();

            $table->string('credential_type', 100)->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_premium')->default(false);

            $table->string('docs_url', 500)->nullable();

            $table->timestamps();

            $table->index('node_kind');
            $table->index('category_id');
            $table->index(['is_active', 'is_premium']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
