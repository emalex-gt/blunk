<?php

namespace App\Services\Routes;

use App\Models\PreSale;
use App\Models\User;
use App\Support\IdempotencyResult;

class RoutePreSaleReceiptService
{
    public function __construct(private readonly RoutePreSaleInvoiceService $conversion)
    {
    }

    public function convertToInternalReceipt(PreSale $preSale, array $data, User $user): IdempotencyResult
    {
        return $this->conversion->convert($preSale, [
            ...$data,
            'document_type' => 'receipt',
            'route_internal_receipt' => true,
        ], $user);
    }
}
