<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutePreparationBatchPreSale extends Model
{
    protected $fillable = [
        'route_preparation_batch_id',
        'pre_sale_id',
        'status',
        'total_items',
        'total_amount',
        'error_message',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(RoutePreparationBatch::class, 'route_preparation_batch_id');
    }

    public function preSale(): BelongsTo
    {
        return $this->belongsTo(PreSale::class);
    }
}
