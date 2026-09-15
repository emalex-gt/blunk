<?php

namespace App\Services\Routes;

use App\Models\PreSale;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\IdempotencyResult;

class RoutePreSaleReceiptService
{
    public function __construct(
        private readonly RoutePreSaleInvoiceService $conversion,
        private readonly RoutePreSaleFelEligibilityService $eligibility,
        private readonly RouteCashOperationGuard $cash,
    )
    {
    }

    public function convertToInternalReceipt(PreSale $preSale, array $data, User $user, bool $requiresOpenCashSession = true): IdempotencyResult
    {
        if ($requiresOpenCashSession) {
            $this->cash->requireOpen((int) $preSale->business_id, (int) $preSale->branch_id);
        }
        $result = $this->eligibility->persist($preSale);
        $requiresEligibleCustomer = (bool) TenantSetting::query()
            ->where('business_id', $preSale->business_id)
            ->value('route_pre_sale_require_fel_eligible_customer');

        if ($requiresEligibleCustomer && ! $result['eligible']) {
            $this->eligibility->assertEligible($preSale, 'pre_sale');
        }

        return $this->conversion->convert($preSale, [
            ...$data,
            'document_type' => 'receipt',
            'route_internal_receipt' => true,
        ], $user);
    }
}
