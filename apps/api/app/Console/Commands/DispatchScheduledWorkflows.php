<?php

namespace App\Console\Commands;

use App\Enums\TriggerType;
use App\Models\Workflow;
use App\Services\WorkflowDispatchService;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DispatchScheduledWorkflows extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workflows:dispatch-scheduled
                            {--dry-run : Show which workflows would be dispatched without executing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch scheduled workflows that are due to run';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $now = Carbon::now();

        $this->info("Checking scheduled workflows at {$now->toDateTimeString()}...");

        $workflows = Workflow::query()
            ->active()
            ->byTriggerType(TriggerType::Schedule)
            ->whereNotNull('trigger_config')
            ->with('workspace')
            ->get();

        $dispatched = 0;
        $skipped = 0;

        foreach ($workflows as $workflow) {
            $config = $workflow->trigger_config;

            if (empty($config['cron'])) {
                $this->warn("Workflow #{$workflow->id} ({$workflow->name}) has no cron expression, skipping.");
                $skipped++;

                continue;
            }

            try {
                $cron = new CronExpression($config['cron']);

                // Check if the workflow should run now (within the last minute)
                if ($this->shouldRunNow($cron, $now, $config)) {
                    // Prevent duplicate dispatches if scheduler runs twice in same minute
                    $lockKey = "schedule-lock:{$workflow->id}:".$now->format('YmdHi');
                    $lock = Cache::lock($lockKey, 120);

                    if (! $lock->get()) {
                        $this->line("  Skipped (already dispatched): {$workflow->name} (ID: {$workflow->id})");
                        $skipped++;

                        continue;
                    }

                    if ($dryRun) {
                        $lock->release();
                        $this->line("  [DRY-RUN] Would dispatch: {$workflow->name} (ID: {$workflow->id})");
                    } else {
                        $this->dispatchWorkflow($workflow, $config);
                        $this->info("  Dispatched: {$workflow->name} (ID: {$workflow->id})");
                    }
                    $dispatched++;
                } else {
                    $nextRun = $cron->getNextRunDate($now);
                    $this->line("  Skipped: {$workflow->name} - Next run: {$nextRun->format('Y-m-d H:i:s')}");
                    $skipped++;
                }
            } catch (\Exception $e) {
                $this->error("  Error processing workflow #{$workflow->id}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Summary: Dispatched {$dispatched} workflows, Skipped {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Check if the workflow should run now.
     */
    private function shouldRunNow(CronExpression $cron, Carbon $now, array $config): bool
    {
        // Check timezone
        $timezone = $config['timezone'] ?? config('app.timezone');
        $nowInTimezone = $now->copy()->setTimezone($timezone);

        // Check if current minute matches cron expression
        if (! $cron->isDue($nowInTimezone)) {
            return false;
        }

        // Check start/end dates if specified
        if (! empty($config['start_date'])) {
            $startDate = Carbon::parse($config['start_date']);
            if ($now->lt($startDate)) {
                return false;
            }
        }

        if (! empty($config['end_date'])) {
            $endDate = Carbon::parse($config['end_date']);
            if ($now->gt($endDate)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dispatch the workflow for execution via WorkflowDispatchService.
     * This ensures contract validation, policy validation, and rate limiting are applied.
     */
    private function dispatchWorkflow(Workflow $workflow, array $config): void
    {
        $triggerData = [
            'trigger_type' => 'schedule',
            'scheduled_at' => now()->toIso8601String(),
            'cron' => $config['cron'],
            'timezone' => $config['timezone'] ?? config('app.timezone'),
        ];

        app(WorkflowDispatchService::class)->dispatchLowPriority(
            workflow: $workflow,
            mode: 'schedule',
            triggerData: $triggerData,
        );
    }
}
