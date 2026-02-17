<?php

namespace App\Services;

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Jobs\ExecuteWorkflowJob;
use App\Models\Execution;
use App\Models\ExecutionReplayPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeterministicReplayService
{
    /**
     * @param  array<string, mixed>  $triggerData
     * @param  array<int, array<string, mixed>>  $fixtures
     */
    public function capture(
        Execution $execution,
        string $mode = 'capture',
        ?Execution $sourceExecution = null,
        array $triggerData = [],
        array $fixtures = []
    ): ExecutionReplayPack {
        $workflow = $execution->workflow;

        return ExecutionReplayPack::query()->updateOrCreate(
            ['execution_id' => $execution->id],
            [
                'workspace_id' => $execution->workspace_id,
                'workflow_id' => $execution->workflow_id,
                'source_execution_id' => $sourceExecution?->id,
                'mode' => $mode,
                'deterministic_seed' => (string) Str::uuid(),
                'workflow_snapshot' => [
                    'id' => $workflow->id,
                    'name' => $workflow->name,
                    'nodes' => $workflow->nodes,
                    'edges' => $workflow->edges,
                    'settings' => $workflow->settings,
                ],
                'trigger_snapshot' => $triggerData,
                'fixtures' => $fixtures,
                'environment_snapshot' => [
                    'app_env' => config('app.env'),
                    'app_url' => config('app.url'),
                    'captured_by' => 'api-dispatch',
                ],
                'captured_at' => now(),
                'expires_at' => now()->addDays(30),
            ]
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $fixtures
     */
    public function appendFixtures(Execution $execution, array $fixtures): void
    {
        if ($fixtures === []) {
            return;
        }

        $pack = $execution->replayPack;
        if (! $pack) {
            return;
        }

        $existing = $pack->fixtures ?? [];

        $indexed = [];
        foreach ($existing as $fixture) {
            $key = $fixture['request_fingerprint'] ?? md5(json_encode($fixture));
            $indexed[$key] = $fixture;
        }

        foreach ($fixtures as $fixture) {
            $key = $fixture['request_fingerprint'] ?? md5(json_encode($fixture));
            $indexed[$key] = $fixture;
        }

        $pack->update([
            'fixtures' => array_values($indexed),
            'captured_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $overrideTriggerData
     * @return array{execution: Execution, replay_pack: ExecutionReplayPack}
     */
    public function rerunDeterministically(
        Execution $sourceExecution,
        User $user,
        bool $useLatestWorkflow = false,
        ?array $overrideTriggerData = null
    ): array {
        $sourcePack = $sourceExecution->replayPack;
        if (! $sourcePack) {
            throw new \RuntimeException('Source execution does not have a replay pack.');
        }

        $workflow = $sourceExecution->workflow;

        $deterministicWorkflowSnapshot = null;
        if (! $useLatestWorkflow && is_array($sourcePack->workflow_snapshot)) {
            $deterministicWorkflowSnapshot = $sourcePack->workflow_snapshot;
        }

        $triggerData = $overrideTriggerData ?? $sourcePack->trigger_snapshot ?? $sourceExecution->trigger_data ?? [];

        [$execution, $replayPack] = DB::transaction(function () use ($sourceExecution, $user, $triggerData, $sourcePack) {
            $execution = Execution::query()->create([
                'workflow_id' => $sourceExecution->workflow_id,
                'workspace_id' => $sourceExecution->workspace_id,
                'status' => ExecutionStatus::Pending,
                'mode' => ExecutionMode::Retry,
                'triggered_by' => $user->id,
                'trigger_data' => $triggerData,
                'attempt' => 1,
                'max_attempts' => $sourceExecution->max_attempts,
                'parent_execution_id' => $sourceExecution->id,
                'replay_of_execution_id' => $sourceExecution->id,
                'is_deterministic_replay' => true,
            ]);

            $replayPack = $this->capture(
                execution: $execution,
                mode: 'replay',
                sourceExecution: $sourceExecution,
                triggerData: $triggerData,
                fixtures: $sourcePack->fixtures ?? []
            );

            return [$execution, $replayPack];
        });

        $deterministicContext = [
            'mode' => 'replay',
            'seed' => $replayPack->deterministic_seed,
            'fixtures' => $replayPack->fixtures ?? [],
            'source_execution_id' => $sourceExecution->id,
        ];

        if ($deterministicWorkflowSnapshot !== null) {
            $deterministicContext['workflow_snapshot'] = $deterministicWorkflowSnapshot;
        }

        ExecuteWorkflowJob::dispatch(
            $workflow,
            $execution,
            'default',
            $triggerData,
            $deterministicContext
        )->afterCommit();

        return [
            'execution' => $execution,
            'replay_pack' => $replayPack,
        ];
    }
}
