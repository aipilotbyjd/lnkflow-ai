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
        Schema::create('workflow_contract_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->nullable()->constrained('workflow_versions')->nullOnDelete();
            $table->char('graph_hash', 64);
            $table->enum('status', ['valid', 'warning', 'invalid'])->default('valid');
            $table->json('contracts');
            $table->json('issues')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['workflow_id', 'graph_hash']);
            $table->index(['workflow_id', 'generated_at']);
        });

        Schema::create('execution_replay_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_execution_id')->nullable()->constrained('executions')->nullOnDelete();
            $table->enum('mode', ['capture', 'replay'])->default('capture');
            $table->uuid('deterministic_seed');
            $table->json('workflow_snapshot');
            $table->json('trigger_snapshot')->nullable();
            $table->json('fixtures')->nullable();
            $table->json('environment_snapshot')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'captured_at']);
            $table->index(['workflow_id', 'captured_at']);
            $table->index('source_execution_id');
        });

        Schema::create('connector_call_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_node_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('connector_key', 120);
            $table->string('connector_operation', 120);
            $table->string('provider')->nullable();
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->boolean('is_retry')->default(false);
            $table->enum('status', ['success', 'client_error', 'server_error', 'timeout', 'network_error', 'cancelled']);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->char('request_fingerprint', 64);
            $table->string('idempotency_key')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('happened_at', 3);
            $table->timestamps();

            $table->index(['workspace_id', 'connector_key', 'happened_at']);
            $table->index('execution_id');
            $table->index(['status', 'happened_at']);
        });

        Schema::create('connector_metrics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('connector_key', 120);
            $table->string('connector_operation', 120);
            $table->date('day');
            $table->unsignedInteger('total_calls')->default(0);
            $table->unsignedInteger('success_calls')->default(0);
            $table->unsignedInteger('failure_calls')->default(0);
            $table->unsignedInteger('retry_calls')->default(0);
            $table->unsignedInteger('timeout_calls')->default(0);
            $table->unsignedInteger('p50_latency_ms')->nullable();
            $table->unsignedInteger('p95_latency_ms')->nullable();
            $table->unsignedInteger('p99_latency_ms')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'connector_key', 'connector_operation', 'day']);
        });

        Schema::create('workflow_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 100);
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('payload')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired'])->default('pending');
            $table->timestamp('due_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('decision_payload')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['execution_id', 'status']);
        });

        Schema::create('workspace_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete()->unique();
            $table->boolean('enabled')->default(false);
            $table->json('allowed_node_types')->nullable();
            $table->json('blocked_node_types')->nullable();
            $table->json('allowed_ai_models')->nullable();
            $table->json('blocked_ai_models')->nullable();
            $table->decimal('max_execution_cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('max_ai_tokens')->nullable();
            $table->json('redaction_rules')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('git_branch', 160);
            $table->string('base_branch', 160)->default('main');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            $table->unique(['workspace_id', 'git_branch']);
        });

        Schema::create('workflow_environment_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_environment_id')->nullable()->constrained('workspace_environments')->nullOnDelete();
            $table->foreignId('to_environment_id')->nullable()->constrained('workspace_environments')->nullOnDelete();
            $table->foreignId('workflow_version_id')->nullable()->constrained('workflow_versions')->nullOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('action', ['promote', 'rollback', 'sync']);
            $table->string('commit_sha', 64)->nullable();
            $table->json('diff_summary')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'workflow_id', 'created_at']);
        });

        Schema::create('execution_runbooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete()->unique();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->string('title');
            $table->text('summary');
            $table->json('steps');
            $table->json('tags')->nullable();
            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workflow_id', 'created_at']);
        });

        Schema::create('workflow_contract_test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_contract_snapshot_id')->nullable()->constrained('workflow_contract_snapshots')->nullOnDelete();
            $table->enum('status', ['passed', 'failed'])->default('passed');
            $table->json('results');
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['workspace_id', 'executed_at']);
        });

        Schema::table('executions', function (Blueprint $table) {
            $table->foreignId('replay_of_execution_id')->nullable()->after('parent_execution_id')->constrained('executions')->nullOnDelete();
            $table->boolean('is_deterministic_replay')->default(false)->after('replay_of_execution_id');
            $table->decimal('estimated_cost_usd', 10, 4)->nullable()->after('duration_ms');

            $table->index('replay_of_execution_id');
            $table->index(['workspace_id', 'is_deterministic_replay']);
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->json('input_schema')->nullable()->after('config_schema');
            $table->decimal('cost_hint_usd', 10, 4)->nullable()->after('credential_type');
            $table->unsignedInteger('latency_hint_ms')->nullable()->after('cost_hint_usd');

            $table->index('cost_hint_usd');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex(['cost_hint_usd']);
            $table->dropColumn(['input_schema', 'cost_hint_usd', 'latency_hint_ms']);
        });

        Schema::table('executions', function (Blueprint $table) {
            $table->dropIndex(['replay_of_execution_id']);
            $table->dropIndex(['workspace_id', 'is_deterministic_replay']);
            $table->dropConstrainedForeignId('replay_of_execution_id');
            $table->dropColumn(['is_deterministic_replay', 'estimated_cost_usd']);
        });

        Schema::dropIfExists('workflow_contract_test_runs');
        Schema::dropIfExists('execution_runbooks');
        Schema::dropIfExists('workflow_environment_releases');
        Schema::dropIfExists('workspace_environments');
        Schema::dropIfExists('workspace_policies');
        Schema::dropIfExists('workflow_approvals');
        Schema::dropIfExists('connector_metrics_daily');
        Schema::dropIfExists('connector_call_attempts');
        Schema::dropIfExists('execution_replay_packs');
        Schema::dropIfExists('workflow_contract_snapshots');
    }
};
