<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreSaleItem extends Model
{
    protected $fillable = [
        'business_id',
        'pre_sale_id',
        'product_id',
        'price_type_id',
        'quantity',
        'unit_price',
        'discount',
        'total',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function preSale(): BelongsTo
    {
        return $this->belongsTo(PreSale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
