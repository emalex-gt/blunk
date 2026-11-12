<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditReceiptLineInvoice extends Model
{
    protected $table = 'credit_receipt_line_invoice';

    protected $fillable = [
        'business_id',
        'credit_receipt_line_id',
        'sale_id',
        'sale_line_id',
        'quantity',
        'amount',
        'reversed_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(CreditReceiptLine::class, 'credit_receipt_line_id');
    }
}
