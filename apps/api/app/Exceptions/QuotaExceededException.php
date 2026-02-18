<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class QuotaExceededException extends HttpException
{
    public function __construct(
        public readonly string $resource,
        public readonly int $currentCount,
        public readonly int $maxAllowed,
        string $message = '',
    ) {
        $message = $message ?: "Quota exceeded for {$resource}. Current: {$currentCount}, Max: {$maxAllowed}.";
        parent::__construct(402, $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => 'quota_exceeded',
            'resource' => $this->resource,
            'current_count' => $this->currentCount,
            'max_allowed' => $this->maxAllowed,
            'message' => $this->getMessage(),
        ];
    }
}
