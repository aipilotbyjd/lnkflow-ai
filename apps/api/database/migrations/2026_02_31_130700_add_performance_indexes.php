<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Performance optimization: Add additional indexes for common query patterns.
     */
    public function up(): void
    {
        // Add indexes to executions table for better query performance
        Schema::table('executions', function (Blueprint $table) {
            // Composite index for workspace + status (stats queries)
            $table->index(['workspace_id', 'status'], 'idx_executions_workspace_status');

            // Index for workflow + created_at (workflow execution history)
            $table->index(['workflow_id', 'created_at'], 'idx_executions_workflow_created');

            // Index for mode filtering
            $table->index('mode', 'idx_executions_mode');
        });

        // Add indexes to execution_nodes table
        Schema::table('execution_nodes', function (Blueprint $table) {
            // Composite index for execution + status (node status queries)
            $table->index(['execution_id', 'status'], 'idx_execution_nodes_execution_status');

            // Index for sequence ordering
            $table->index(['execution_id', 'sequence'], 'idx_execution_nodes_execution_sequence');
        });

        // Add indexes to workflows table
        Schema::table('workflows', function (Blueprint $table) {
            // Composite index for workspace + active status
            $table->index(['workspace_id', 'is_active'], 'idx_workflows_workspace_active');

            // Index for trigger type (scheduled workflow queries)
            $table->index('trigger_type', 'idx_workflows_trigger_type');
        });

        // Add indexes to job_statuses table
        Schema::table('job_statuses', function (Blueprint $table) {
            // Index for job lookup by job_id (already should be unique, but ensure indexed)
            $table->index('status', 'idx_job_statuses_status');

            // Composite index for execution lookups
            $table->index(['execution_id', 'status'], 'idx_job_statuses_execution_status');
        });

        // Add indexes to webhooks table
        Schema::table('webhooks', function (Blueprint $table) {
            // Index for UUID lookup (critical for webhook receiver performance)
            $table->index('uuid', 'idx_webhooks_uuid');

            // Index for active webhooks
            $table->index(['workspace_id', 'is_active'], 'idx_webhooks_workspace_active');
        });

        // Add indexes to credentials table
        Schema::table('credentials', function (Blueprint $table) {
            // Composite index for workspace + type
            $table->index(['workspace_id', 'type'], 'idx_credentials_workspace_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->dropIndex('idx_executions_workspace_status');
            $table->dropIndex('idx_executions_workflow_created');
            $table->dropIndex('idx_executions_mode');
        });

        Schema::table('execution_nodes', function (Blueprint $table) {
            $table->dropIndex('idx_execution_nodes_execution_status');
            $table->dropIndex('idx_execution_nodes_execution_sequence');
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->dropIndex('idx_workflows_workspace_active');
            $table->dropIndex('idx_workflows_trigger_type');
        });

        Schema::table('job_statuses', function (Blueprint $table) {
            $table->dropIndex('idx_job_statuses_status');
            $table->dropIndex('idx_job_statuses_execution_status');
        });

        Schema::table('webhooks', function (Blueprint $table) {
            $table->dropIndex('idx_webhooks_uuid');
            $table->dropIndex('idx_webhooks_workspace_active');
        });

        Schema::table('credentials', function (Blueprint $table) {
            $table->dropIndex('idx_credentials_workspace_type');
        });
    }
};
