<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

class BchatRoomSessionException extends Exception
{
    protected string $errorCode;
    protected int $httpStatus;

    public function __construct(
        string $message,
        string $errorCode = 'ROOM_SESSION_ERROR',
        int $httpStatus = 400,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}

