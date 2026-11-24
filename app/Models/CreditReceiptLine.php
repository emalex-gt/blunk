<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditReceiptLine extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'credit_receipt_id',
        'product_id',
        'variant_id',
        'product_name',
        'sku',
        'quantity',
        'qty_reserved',
        'qty_invoiced',
        'qty_cancelled',
        'qty_pending',
        'unit_price',
        'discount_amount',
        'subtotal_before_discount','line_discount_amount','general_discount_amount','total_after_discount','line_discount_reason',
        'line_total',
        'pending_total',
        'status',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'qty_reserved' => 'decimal:4',
        'qty_invoiced' => 'decimal:4',
        'qty_cancelled' => 'decimal:4',
        'qty_pending' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'subtotal_before_discount' => 'decimal:2','line_discount_amount' => 'decimal:2','general_discount_amount' => 'decimal:2','total_after_discount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'pending_total' => 'decimal:2',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CreditReceipt::class, 'credit_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CreditReceiptLineInvoice::class);
    }
}
