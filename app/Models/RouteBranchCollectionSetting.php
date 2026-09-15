<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteBranchCollectionSetting extends Model
{
    protected $fillable = [
        'branch_id',
        'collection_workflow_mode',
        'allowed_payment_methods',
        'primary_payment_method',
    ];

    protected $casts = [
        'allowed_payment_methods' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
