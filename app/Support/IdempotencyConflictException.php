<?php

namespace App\Support;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class IdempotencyConflictException extends ConflictHttpException
{
    public const PAYLOAD_MISMATCH = 'payload_mismatch';
    public const PROCESSING = 'processing';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}
