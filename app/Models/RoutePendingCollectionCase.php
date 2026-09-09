<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutePendingCollectionCase extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'sale_id', 'pre_sale_id', 'route_delivery_batch_pre_sale_id',
        'route_external_delivery_reconciliation_item_id', 'route_delivery_stop_id', 'delivery_origin',
        'original_delivery_user_id', 'assigned_to', 'status', 'opened_at', 'resolved_at', 'resolved_by',
        'resolution_route_delivery_collection_id', 'next_follow_up_at', 'not_applicable_at',
        'not_applicable_by', 'not_applicable_reason',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
        'next_follow_up_at' => 'datetime',
        'not_applicable_at' => 'datetime',
    ];

    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function preSale(): BelongsTo { return $this->belongsTo(PreSale::class); }
    public function entry(): BelongsTo { return $this->belongsTo(RouteDeliveryBatchPreSale::class, 'route_delivery_batch_pre_sale_id'); }
    public function reconciliationItem(): BelongsTo { return $this->belongsTo(RouteExternalDeliveryReconciliationItem::class, 'route_external_delivery_reconciliation_item_id'); }
    public function stop(): BelongsTo { return $this->belongsTo(RouteDeliveryStop::class, 'route_delivery_stop_id'); }
    public function originalDeliveryUser(): BelongsTo { return $this->belongsTo(User::class, 'original_delivery_user_id'); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
    public function resolutionCollection(): BelongsTo { return $this->belongsTo(RouteDeliveryCollection::class, 'resolution_route_delivery_collection_id'); }
    public function notApplicableBy(): BelongsTo { return $this->belongsTo(User::class, 'not_applicable_by'); }
    public function events(): HasMany { return $this->hasMany(RoutePendingCollectionEvent::class)->orderBy('occurred_at')->orderBy('id'); }
}
