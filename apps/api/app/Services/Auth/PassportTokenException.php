<?php

namespace App\Services\Auth;

use RuntimeException;

class PassportTokenException extends RuntimeException
{
    public function __construct(
        public readonly string $oauthError,
        public readonly string $oauthErrorDescription,
        public readonly int $statusCode = 400,
    ) {
        parent::__construct($oauthErrorDescription, $statusCode);
    }
}
