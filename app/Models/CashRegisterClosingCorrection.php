<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegisterClosingCorrection extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'original_cash_register_session_id', 'correction_cash_register_session_id',
        'sale_id', 'source_type', 'source_id', 'correction_type', 'payment_method', 'amount', 'occurred_at',
        'responsible_user_id', 'reason', 'metadata',
    ];

    protected $casts = ['amount' => 'decimal:2', 'occurred_at' => 'datetime', 'metadata' => 'array'];

    public function originalSession(): BelongsTo { return $this->belongsTo(CashRegisterSession::class, 'original_cash_register_session_id'); }
    public function correctionSession(): BelongsTo { return $this->belongsTo(CashRegisterSession::class, 'correction_cash_register_session_id'); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function responsibleUser(): BelongsTo { return $this->belongsTo(User::class, 'responsible_user_id'); }
}
