<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCostRecalculationItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_cost_recalculation_run_id',
        'product_id',
        'previous_cost',
        'proposed_cost',
        'physical_stock',
        'covered_quantity',
        'missing_quantity',
        'weighted_quantity',
        'acquisition_count',
        'sources',
        'selected_acquisitions',
        'calculation_status',
    ];

    protected $casts = [
        'sources' => 'array',
        'previous_cost' => 'decimal:5',
        'proposed_cost' => 'decimal:5',
        'physical_stock' => 'decimal:5',
        'covered_quantity' => 'decimal:5',
        'missing_quantity' => 'decimal:5',
        'weighted_quantity' => 'decimal:5',
        'selected_acquisitions' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductCostRecalculationRun::class, 'product_cost_recalculation_run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
