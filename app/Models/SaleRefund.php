<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleRefund extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'route_operation_return_id', 'sale_id', 'sale_payment_id',
        'amount', 'original_payment_method', 'refund_method', 'status', 'reference',
        'refunded_to', 'refunded_by', 'refunded_at', 'cash_register_session_id',
        'cash_movement_id', 'idempotency_key',
    ];

    protected $casts = ['amount' => 'decimal:2', 'refunded_at' => 'datetime'];

    public function operationReturn(): BelongsTo { return $this->belongsTo(RouteOperationReturn::class, 'route_operation_return_id'); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function payment(): BelongsTo { return $this->belongsTo(SalePayment::class, 'sale_payment_id'); }
    public function refundedBy(): BelongsTo { return $this->belongsTo(User::class, 'refunded_by'); }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id'); }
    public function cashMovement(): BelongsTo { return $this->belongsTo(CashMovement::class); }
}
