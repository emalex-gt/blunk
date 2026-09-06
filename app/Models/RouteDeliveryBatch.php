<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteDeliveryBatch extends Model
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'business_id', 'branch_id', 'route_work_day_id', 'route_zone_id', 'delivered_by', 'delivered_at',
        'status', 'stock_deduction_timing', 'invoicing_mode', 'fel_automation_enabled',
        'delivery_tracking_snapshot', 'collection_responsibility_snapshot', 'operation_settings_snapshotted_at',
        'total_pre_sales', 'total_items', 'total_amount', 'notes',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'operation_settings_snapshotted_at' => 'datetime',
        'fel_automation_enabled' => 'boolean',
        'total_amount' => 'decimal:2',
    ];

    public function workDay(): BelongsTo { return $this->belongsTo(RouteWorkDay::class, 'route_work_day_id'); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function zone(): BelongsTo { return $this->belongsTo(RouteZone::class, 'route_zone_id'); }
    public function deliveredBy(): BelongsTo { return $this->belongsTo(User::class, 'delivered_by'); }
    public function preSales(): HasMany { return $this->hasMany(RouteDeliveryBatchPreSale::class); }
    public function externalDeliveryReconciliation(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(RouteExternalDeliveryReconciliation::class); }

    public function isExternalDeliveryTracking(): bool
    {
        return $this->delivery_tracking_snapshot === 'external';
    }
}
