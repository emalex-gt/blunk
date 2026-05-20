<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBranchStock extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'product_id',
        'stock',
    ];

    protected $casts = [
        'stock' => 'float',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
