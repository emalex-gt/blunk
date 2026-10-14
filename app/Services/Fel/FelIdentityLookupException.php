<?php

namespace App\Services\Fel;

use Throwable;

class FelIdentityLookupException extends FelException
{
    public const NOT_FOUND = 'not_found';
    public const INACTIVE = 'inactive';
    public const UNAUTHORIZED = 'unauthorized';
    public const NETWORK = 'network';
    public const MALFORMED_RESPONSE = 'malformed_response';
    public const REMOTE_FAILURE = 'remote_failure';

    public function __construct(
        private readonly string $reason,
        string $message,
        ?Throwable $previous = null,
        ?array $responsePayload = null,
    ) {
        parent::__construct($message, 0, $previous, $responsePayload);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
