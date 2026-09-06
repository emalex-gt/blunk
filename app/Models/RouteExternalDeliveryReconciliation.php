<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteExternalDeliveryReconciliation extends Model
{
    protected $fillable = ['business_id', 'branch_id', 'route_delivery_batch_id', 'opened_by', 'opened_at', 'completed_at'];

    protected $casts = ['opened_at' => 'datetime', 'completed_at' => 'datetime'];

    public function batch(): BelongsTo { return $this->belongsTo(RouteDeliveryBatch::class, 'route_delivery_batch_id'); }
    public function openedBy(): BelongsTo { return $this->belongsTo(User::class, 'opened_by'); }
    public function items(): HasMany { return $this->hasMany(RouteExternalDeliveryReconciliationItem::class); }
}
