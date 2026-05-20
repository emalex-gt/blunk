<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'business_id',
        'sale_id',
        'product_id',
        'price_type_id',
        'product_name',
        'quantity',
        'unit_price',
        'original_price',
        'price_source',
        'manual_price',
        'unit_cost',
        'total',
        'discount_amount',
        'total_before_discount',
        'total_after_discount',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'manual_price' => 'boolean',
        'unit_cost' => 'decimal:2',
        'total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_before_discount' => 'decimal:2',
        'total_after_discount' => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceType(): BelongsTo
    {
        return $this->belongsTo(PriceType::class);
    }
}
