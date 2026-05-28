<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditCustomerTransfer extends Model
{
    protected $fillable = [
        'business_id',
        'from_customer_id',
        'to_customer_id',
        'transferred_by',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
