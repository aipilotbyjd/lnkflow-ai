<?php

namespace App\Enums;

enum ExecutionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Waiting = 'waiting';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled]);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Running, self::Waiting]);
    }
}
