<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteExternalDeliveryReconciliationItem extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'route_external_delivery_reconciliation_id', 'route_delivery_batch_pre_sale_id',
        'pre_sale_id', 'sale_id', 'delivery_tracking_snapshot', 'collection_responsibility_snapshot',
        'delivery_status', 'not_delivered_reason', 'notes', 'reconciled_by', 'reconciled_at',
    ];

    protected $casts = ['reconciled_at' => 'datetime'];

    public function reconciliation(): BelongsTo { return $this->belongsTo(RouteExternalDeliveryReconciliation::class, 'route_external_delivery_reconciliation_id'); }
    public function entry(): BelongsTo { return $this->belongsTo(RouteDeliveryBatchPreSale::class, 'route_delivery_batch_pre_sale_id'); }
    public function preSale(): BelongsTo { return $this->belongsTo(PreSale::class); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function reconciledBy(): BelongsTo { return $this->belongsTo(User::class, 'reconciled_by'); }
    public function deliveryCollection(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(RouteDeliveryCollection::class, 'route_external_delivery_reconciliation_item_id'); }
    public function revisions(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(RouteExternalDeliveryReconciliationItemRevision::class); }
}
