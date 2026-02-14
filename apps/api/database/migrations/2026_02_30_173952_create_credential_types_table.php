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
        Schema::create('credential_types', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon', 50);
            $table->string('color', 20);
            $table->json('fields_schema');
            $table->json('test_config')->nullable();
            $table->string('docs_url', 500)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credential_types');
    }
};
