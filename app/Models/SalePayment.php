<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }
}
