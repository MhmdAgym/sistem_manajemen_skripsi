<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Throwable;

class BchatCommitRelayException extends Exception
{
    public function __construct(
        string $message,
        protected string $errorCode = 'COMMIT_RELAY_ERROR',
        protected int $statusCode = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success'    => false,
            'message'    => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], $this->statusCode);
    }
}

