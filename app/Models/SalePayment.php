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
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'details' => 'array',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
