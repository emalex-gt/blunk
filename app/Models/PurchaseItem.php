<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $fillable = [
        'business_id',
        'purchase_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_cost',
        'previous_cost',
        'new_average_cost',
        'total',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:5',
        'previous_cost' => 'decimal:5',
        'new_average_cost' => 'decimal:5',
        'total' => 'decimal:5',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
