<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    protected $fillable = [
        'business_id',
        'sale_id',
        'method',
        'amount',
        'reference',
        'details',
        'collected_by',
        'collected_at',
        'cash_register_session_id',
        'route_pre_sale_collection_id',
        'route_delivery_collection_id',
        'route_post_conversion_collection_id',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'details' => 'array',
        'collected_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function routePreSaleCollection(): BelongsTo
    {
        return $this->belongsTo(RoutePreSaleCollection::class);
    }

    public function routeDeliveryCollection(): BelongsTo
    {
        return $this->belongsTo(RouteDeliveryCollection::class);
    }

    public function routePostConversionCollection(): BelongsTo
    {
        return $this->belongsTo(RoutePostConversionCollection::class);
    }

    public function routeDeliveryCollectionReversal(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RouteDeliveryCollectionReversal::class);
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    public function scopeCaptured(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), 'captured');
    }

    public function scopeReversed(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), 'reversed');
    }
}
