<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteDeliveryCollectionReversal extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'route_delivery_collection_id', 'sale_payment_id',
        'route_pending_collection_case_id', 'previous_case_resolved_by', 'previous_case_resolved_at',
        'reason_code', 'explanation', 'reversed_by', 'reversed_at', 'cash_correction_type',
        'compensating_cash_movement_id', 'operation_idempotency_key_id',
    ];

    protected $casts = [
        'previous_case_resolved_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function collection(): BelongsTo { return $this->belongsTo(RouteDeliveryCollection::class, 'route_delivery_collection_id'); }
    public function salePayment(): BelongsTo { return $this->belongsTo(SalePayment::class); }
    public function pendingCase(): BelongsTo { return $this->belongsTo(RoutePendingCollectionCase::class, 'route_pending_collection_case_id'); }
    public function previousCaseResolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'previous_case_resolved_by'); }
    public function reversedBy(): BelongsTo { return $this->belongsTo(User::class, 'reversed_by'); }
    public function compensatingCashMovement(): BelongsTo { return $this->belongsTo(CashMovement::class, 'compensating_cash_movement_id'); }
    public function operationIdempotencyKey(): BelongsTo { return $this->belongsTo(OperationIdempotencyKey::class); }
}
