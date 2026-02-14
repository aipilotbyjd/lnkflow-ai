<?php

namespace App\Exceptions;

use RuntimeException;

class ContractValidationException extends RuntimeException
{
    /** @var array<int, mixed> */
    private array $issues;

    /**
     * @param  array<int, mixed>  $issues
     */
    public function __construct(array $issues)
    {
        parent::__construct('Workflow has invalid data contracts.');
        $this->issues = $issues;
    }

    /**
     * @return array<int, mixed>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }
}
