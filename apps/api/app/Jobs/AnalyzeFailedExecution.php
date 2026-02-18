<?php

namespace App\Jobs;

use App\Models\Execution;
use App\Models\WorkspacePolicy;
use App\Services\AiAutoFixService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeFailedExecution implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public Execution $execution
    ) {
        $this->onQueue('ai-analysis');
    }

    public function handle(AiAutoFixService $autoFixService): void
    {
        if ($this->execution->status->value !== 'failed') {
            return;
        }

        $result = $autoFixService->analyze($this->execution);

        $policy = WorkspacePolicy::query()
            ->where('workspace_id', $this->execution->workspace_id)
            ->first();

        if (! $policy || ! $policy->ai_auto_fix_enabled) {
            return;
        }

        $threshold = (float) ($policy->ai_auto_fix_confidence_threshold ?? 0.95);
        $suggestions = $result['suggestions'];

        if (! empty($suggestions) && ($suggestions[0]['confidence'] ?? 0) >= $threshold) {
            try {
                $autoFixService->applyFix($result['fix_suggestion'], 0);
                Log::info('AI Auto-Fix applied automatically', [
                    'execution_id' => $this->execution->id,
                    'confidence' => $suggestions[0]['confidence'],
                ]);
            } catch (\Throwable $e) {
                Log::error('AI Auto-Fix auto-apply failed', [
                    'execution_id' => $this->execution->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
