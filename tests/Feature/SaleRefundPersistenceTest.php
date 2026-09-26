<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SaleRefundPersistenceTest extends TestCase
{
    public function test_schema_exposes_direct_immediate_paid_provenance_and_cash_refund_ledger(): void
    {
        $this->assertTrue(Schema::hasColumn('sale_payments', 'route_immediate_paid_entry_id'));
        $this->assertTrue(Schema::hasTable('sale_refunds'));

        foreach ([
            'business_id', 'branch_id', 'route_operation_return_id', 'sale_id', 'sale_payment_id',
            'amount', 'original_payment_method', 'refund_method', 'status', 'refunded_by',
            'refunded_at', 'cash_register_session_id', 'cash_movement_id', 'idempotency_key',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('sale_refunds', $column), $column);
        }

        $amount = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'sale_refunds')
            ->where('column_name', 'amount')
            ->first(['numeric_precision', 'numeric_scale']);
        $this->assertSame(12, (int) $amount->numeric_precision);
        $this->assertSame(2, (int) $amount->numeric_scale);

        $constraints = DB::table('pg_constraint')
            ->whereIn('conname', [
                'sale_payments_route_provenance_check',
                'sale_refunds_state_check',
                'sale_refunds_route_return_unique',
                'sale_refunds_payment_unique',
                'sale_refunds_cash_movement_unique',
                'sale_refunds_idempotency_unique',
            ])
            ->pluck('conname');
        $this->assertCount(6, $constraints);
    }

    public function test_collection_correction_services_guard_confirmed_refunds(): void
    {
        foreach ([
            app_path('Services/Routes/RouteDeliveryCollectionReversalService.php'),
            app_path('Services/Routes/RoutePostConversionCollectionReversalService.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertStringContainsString("SaleRefund::query()->where('sale_payment_id'", $source);
            $this->assertStringContainsString("->where('status', 'confirmed')->exists()", $source);
            $this->assertStringContainsString('Un pago ya reembolsado no puede convertirse después en una corrección de cobro.', $source);
        }
    }
}
