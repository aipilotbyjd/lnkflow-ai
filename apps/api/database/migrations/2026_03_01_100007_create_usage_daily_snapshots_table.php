<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('credits_used')->default(0);
            $table->unsignedInteger('executions_total')->default(0);
            $table->unsignedInteger('executions_succeeded')->default(0);
            $table->unsignedInteger('executions_failed')->default(0);
            $table->unsignedInteger('nodes_executed')->default(0);
            $table->unsignedBigInteger('data_transfer_bytes')->default(0);
            $table->unsignedSmallInteger('active_workflows')->default(0);
            $table->unsignedSmallInteger('peak_concurrent_executions')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_daily_snapshots');
    }
};
