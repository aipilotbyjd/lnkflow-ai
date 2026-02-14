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
        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('version_number');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('trigger_type')->nullable();
            $table->json('trigger_config')->nullable();
            $table->json('nodes');
            $table->json('edges');
            $table->json('viewport')->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('change_summary')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_id', 'version_number']);
            $table->index(['workflow_id', 'is_published']);
        });

        // Add current_version_id to workflows table
        Schema::table('workflows', function (Blueprint $table) {
            $table->foreignId('current_version_id')->nullable()->after('settings');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('current_version_id');
        });

        Schema::dropIfExists('workflow_versions');
    }
};
