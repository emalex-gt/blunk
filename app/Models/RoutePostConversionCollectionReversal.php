<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutePostConversionCollectionReversal extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'route_post_conversion_collection_id', 'sale_payment_id', 'reason_code',
        'explanation', 'reversed_by', 'reversed_at', 'cash_correction_type', 'compensating_cash_movement_id',
        'operation_idempotency_key_id',
    ];

    protected $casts = ['reversed_at' => 'datetime'];

    public function collection(): BelongsTo { return $this->belongsTo(RoutePostConversionCollection::class, 'route_post_conversion_collection_id'); }
    public function salePayment(): BelongsTo { return $this->belongsTo(SalePayment::class); }
    public function reversedBy(): BelongsTo { return $this->belongsTo(User::class, 'reversed_by'); }
    public function compensatingCashMovement(): BelongsTo { return $this->belongsTo(CashMovement::class, 'compensating_cash_movement_id'); }
}
