<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class PlanLimitException extends HttpException
{
    public function __construct(
        public readonly string $limitType,
        public readonly int $currentUsage,
        public readonly int $limit,
        string $message = '',
    ) {
        $message = $message ?: "Plan limit exceeded for {$limitType}. Current: {$currentUsage}, Limit: {$limit}.";
        parent::__construct(402, $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => 'plan_limit_exceeded',
            'limit_type' => $this->limitType,
            'current_usage' => $this->currentUsage,
            'limit' => $this->limit,
            'message' => $this->getMessage(),
        ];
    }
}
