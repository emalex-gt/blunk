<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegisterClosingSnapshot extends Model
{
    protected $fillable = ['business_id', 'branch_id', 'cash_register_session_id', 'schema_version', 'snapshot', 'captured_at'];

    protected $casts = ['snapshot' => 'array', 'captured_at' => 'datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }
}
