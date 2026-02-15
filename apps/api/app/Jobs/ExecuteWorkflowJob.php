<?php

namespace App\Jobs;

use App\Models\Execution;
use App\Models\JobStatus;
use App\Models\Workflow;
use App\Services\DeterministicReplayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ExecuteWorkflowJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $jobId;

    public int $partition;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Backoff between retries (seconds).
     *
     * @var array<int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * Time (seconds) before job should timeout.
     */
    public int $timeout = 300;

    public function __construct(
        public Workflow $workflow,
        public Execution $execution,
        public string $priority = 'default',
        public array $triggerData = [],
        public array $deterministicContext = [],
    ) {
        $this->jobId = (string) Str::uuid();
        $this->partition = $workflow->workspace_id % $this->getPartitionCount();
        $this->onQueue("workflows-{$priority}");
    }

    /**
     * Unique ID for preventing duplicate jobs.
     */
    public function uniqueId(): string
    {
        return "workflow:{$this->workflow->id}:execution:{$this->execution->id}";
    }

    public function handle(): void
    {
        // Generate unique callback token (64-char hex)
        $callbackToken = bin2hex(random_bytes(32));

        /** @var DeterministicReplayService $replayService */
        $replayService = app(DeterministicReplayService::class);
        if (! $this->execution->replayPack) {
            $replayService->capture(
                execution: $this->execution,
                mode: ($this->deterministicContext['mode'] ?? 'capture'),
                sourceExecution: isset($this->deterministicContext['source_execution_id'])
                    ? Execution::find($this->deterministicContext['source_execution_id'])
                    : null,
                triggerData: $this->triggerData,
                fixtures: $this->deterministicContext['fixtures'] ?? []
            );
        }

        // Create job status record with token
        $jobStatus = JobStatus::create([
            'job_id' => $this->jobId,
            'execution_id' => $this->execution->id,
            'partition' => $this->partition,
            'callback_token' => $callbackToken,
            'status' => 'pending',
        ]);

        // Prepare message for Go engine (includes token for callbacks)
        $message = $this->buildMessage($callbackToken);

        // Publish to Redis Stream (partitioned)
        $streamKey = "linkflow:jobs:partition:{$this->partition}";

        Redis::xadd($streamKey, '*', [
            'payload' => json_encode($message),
        ]);

        $streamMaxLen = (int) config('services.engine.stream_maxlen', 100000);
        if ($streamMaxLen > 0) {
            try {
                Redis::xtrim($streamKey, 'MAXLEN', '~', $streamMaxLen);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Update job status
        $jobStatus->markProcessing();

        // Update execution status
        $this->execution->update(['status' => 'running']);
    }

    /**
     * Build the message payload for Go engine.
     *
     * @return array<string, mixed>
     */
    protected function buildMessage(string $callbackToken): array
    {
        $sensitiveContext = $this->buildSensitiveContext();
        $replayPack = $this->execution->replayPack;
        $deterministicMode = $this->deterministicContext['mode']
            ?? ($this->execution->is_deterministic_replay ? 'replay' : 'capture');
        $deterministicSeed = $this->deterministicContext['seed']
            ?? $replayPack?->deterministic_seed
            ?? (string) Str::uuid();
        $deterministicFixtures = $this->deterministicContext['fixtures']
            ?? $replayPack?->fixtures
            ?? [];

        return [
            'job_id' => $this->jobId,
            'callback_token' => $callbackToken, // Go must include this in callbacks
            'execution_id' => $this->execution->id,
            'workflow_id' => $this->workflow->id,
            'workspace_id' => $this->workflow->workspace_id,
            'partition' => $this->partition,
            'priority' => $this->priority,
            'workflow' => [
                'nodes' => array_values($this->workflow->nodes ?? []),
                'edges' => array_values($this->workflow->edges ?? []),
                'settings' => (object) ($this->workflow->settings ?? []),
            ],
            'trigger_data' => (object) $this->triggerData,
            'credentials' => (object) ($sensitiveContext['credentials'] ?? []),
            'variables' => (object) ($sensitiveContext['variables'] ?? []),
            'callback_url' => $this->getInternalApiUrl().'/api/v1/jobs/callback',
            'progress_url' => $this->getInternalApiUrl().'/api/v1/jobs/progress',
            'deterministic' => [
                'mode' => $deterministicMode,
                'seed' => $deterministicSeed,
                'fixtures' => $deterministicFixtures,
                'source_execution_id' => $this->execution->replay_of_execution_id,
            ],
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Build optional sensitive context sent to the engine.
     *
     * @return array{credentials: array<string, mixed>, variables: array<string, mixed>}
     */
    protected function buildSensitiveContext(): array
    {
        if (! config('services.engine.send_sensitive_context', false)) {
            return [
                'credentials' => [],
                'variables' => [],
            ];
        }

        return [
            'credentials' => $this->getDecryptedCredentials(),
            'variables' => $this->getVariables(),
        ];
    }

    /**
     * Get decrypted credentials used by this workflow.
     * Only decrypts credentials that are actually referenced by nodes.
     *
     * @return array<string, mixed>
     */
    protected function getDecryptedCredentials(): array
    {
        // Extract credential IDs from nodes to only decrypt what's needed
        $usedCredentialIds = collect($this->workflow->nodes)
            ->pluck('data.credentialId')
            ->merge(collect($this->workflow->nodes)->pluck('data.credential_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($usedCredentialIds)) {
            return [];
        }

        return $this->workflow->credentials()
            ->whereIn('credentials.id', $usedCredentialIds)
            ->get()
            ->mapWithKeys(fn ($credential) => [
                $credential->id => [
                    'type' => $credential->type,
                    'data' => $credential->getDecryptedData(),
                ],
            ])
            ->all();
    }

    /**
     * Get workspace variables.
     *
     * @return array<string, mixed>
     */
    protected function getVariables(): array
    {
        return $this->workflow->workspace->variables()
            ->get()
            ->mapWithKeys(fn ($variable) => [
                $variable->key => $variable->is_secret
                    ? $variable->getDecryptedValue()
                    : $variable->value,
            ])
            ->all();
    }

    /**
     * Get the internal API URL for Docker container communication.
     */
    protected function getInternalApiUrl(): string
    {
        // In Docker, use internal service name; otherwise use APP_URL
        return config('services.engine.api_url', 'http://linkflow-api:8000');
    }

    protected function getPartitionCount(): int
    {
        $count = (int) config('services.engine.partition_count', 16);

        return max(1, $count);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->execution->update([
            'status' => 'failed',
            'error' => [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ],
            'finished_at' => now(),
        ]);

        JobStatus::where('job_id', $this->jobId)->first()?->markFailed([
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
        ]);
    }
}
