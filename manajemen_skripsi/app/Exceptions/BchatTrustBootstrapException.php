<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

class BchatTrustBootstrapException extends Exception
{
    protected string $errorCode;
    protected int $statusCode;

    public function __construct(
        string $message,
        string $errorCode = 'BOOTSTRAP_ERROR',
        int $statusCode = 400,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

