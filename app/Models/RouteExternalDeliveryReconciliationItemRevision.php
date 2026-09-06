<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteExternalDeliveryReconciliationItemRevision extends Model
{
    protected $fillable = ['business_id', 'branch_id', 'route_external_delivery_reconciliation_item_id', 'version', 'previous_values', 'new_values', 'correction_reason', 'corrected_by', 'corrected_at'];
    protected $casts = ['previous_values' => 'array', 'new_values' => 'array', 'corrected_at' => 'datetime'];
    public function item(): BelongsTo { return $this->belongsTo(RouteExternalDeliveryReconciliationItem::class, 'route_external_delivery_reconciliation_item_id'); }
    public function correctedBy(): BelongsTo { return $this->belongsTo(User::class, 'corrected_by'); }
}
