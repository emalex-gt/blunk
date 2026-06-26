<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'warehouse_id',
        'product_id',
        'source_type',
        'source_id',
        'source_item_id',
        'quantity',
        'status',
        'created_by',
        'released_at',
        'consumed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'released_at' => 'datetime',
        'consumed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
