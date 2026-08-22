<?php

namespace App\Support;

class IdempotencyResult
{
    public function __construct(
        public readonly bool $replayed,
        public readonly int $resultId,
        public readonly array $responsePayload = [],
    ) {
    }
}
