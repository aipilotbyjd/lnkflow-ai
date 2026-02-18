<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_usage_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('credits_limit')->default(0);
            $table->unsignedInteger('credits_used')->default(0);
            $table->unsignedInteger('credits_overage')->default(0);
            $table->unsignedInteger('executions_total')->default(0);
            $table->unsignedInteger('executions_succeeded')->default(0);
            $table->unsignedInteger('executions_failed')->default(0);
            $table->unsignedInteger('nodes_executed')->default(0);
            $table->unsignedInteger('ai_nodes_executed')->default(0);
            $table->unsignedBigInteger('data_transfer_bytes')->default(0);
            $table->decimal('estimated_cost_usd', 10, 4)->default(0);
            $table->unsignedSmallInteger('active_workflows_count')->default(0);
            $table->unsignedSmallInteger('members_count')->default(0);
            $table->boolean('is_current')->default(false);
            $table->boolean('is_overage_billed')->default(false);
            $table->string('stripe_invoice_id')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'period_start']);
            $table->index(['workspace_id', 'is_current']);
            $table->index(['period_end', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_usage_periods');
    }
};
