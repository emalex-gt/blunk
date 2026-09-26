<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RouteOperationReturn extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'route_delivery_batch_pre_sale_id', 'sale_id',
        'route_external_delivery_reconciliation_item_id', 'route_delivery_stop_id',
        'reason', 'note', 'status', 'goods_received_at', 'goods_received_by',
        'completed_at', 'completed_by', 'idempotency_key',
    ];

    protected $casts = [
        'goods_received_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function entry(): BelongsTo { return $this->belongsTo(RouteDeliveryBatchPreSale::class, 'route_delivery_batch_pre_sale_id'); }
    public function externalDeliveryItem(): BelongsTo { return $this->belongsTo(RouteExternalDeliveryReconciliationItem::class, 'route_external_delivery_reconciliation_item_id'); }
    public function deliveryStop(): BelongsTo { return $this->belongsTo(RouteDeliveryStop::class); }
    public function stockMovements(): HasMany { return $this->hasMany(StockMovement::class, 'route_operation_return_id'); }
    public function refund(): HasOne { return $this->hasOne(SaleRefund::class); }
}
