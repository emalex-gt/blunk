<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerTaxLookup extends Model
{
    protected $fillable = [
        'business_id',
        'country',
        'doc_type',
        'doc_number',
        'name',
        'provider',
        'raw_response',
        'last_lookup_at',
    ];

    protected $casts = [
        'raw_response' => 'array',
        'last_lookup_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
