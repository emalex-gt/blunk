<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransferLine extends Model
{
    protected $fillable = [
        'business_id',
        'inventory_transfer_id',
        'product_id',
        'variant_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
