<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteDeliveryBatchPreSale extends Model
{
    protected $fillable = ['route_delivery_batch_id', 'pre_sale_id', 'sale_id', 'status', 'payment_method', 'fel_dispatch_status', 'error_message'];

    public function batch(): BelongsTo { return $this->belongsTo(RouteDeliveryBatch::class, 'route_delivery_batch_id'); }
    public function preSale(): BelongsTo { return $this->belongsTo(PreSale::class); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
}
