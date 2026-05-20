<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'product_id',
        'type',
        'quantity',
        'previous_stock',
        'new_stock',
        'note',
        'created_by',
        'user_id',
    ];

    protected $casts = [
        'quantity' => 'float',
        'previous_stock' => 'float',
        'new_stock' => 'float',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
