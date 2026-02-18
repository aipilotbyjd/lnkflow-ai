<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class FeatureNotAvailableException extends HttpException
{
    public function __construct(
        public readonly string $feature,
        public readonly string $requiredPlan,
        string $message = '',
    ) {
        $message = $message ?: "The '{$feature}' feature requires the {$requiredPlan} plan or higher.";
        parent::__construct(403, $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => 'feature_not_available',
            'feature' => $this->feature,
            'required_plan' => $this->requiredPlan,
            'message' => $this->getMessage(),
        ];
    }
}
