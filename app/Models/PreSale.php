<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreSale extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'route_work_day_id',
        'route_visit_id',
        'route_zone_id',
        'customer_id',
        'seller_id',
        'status',
        'subtotal',
        'discount_total',
        'total',
        'notes',
        'submitted_at',
        'cancelled_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PreSaleItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function workDay(): BelongsTo
    {
        return $this->belongsTo(RouteWorkDay::class, 'route_work_day_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(RouteVisit::class, 'route_visit_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(RouteZone::class, 'route_zone_id');
    }
}
