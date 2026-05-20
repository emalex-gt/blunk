<?php

namespace App\Services\Fel;

use RuntimeException;
use Throwable;

class FelException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?array $responsePayload = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function responsePayload(): ?array
    {
        return $this->responsePayload;
    }
}
