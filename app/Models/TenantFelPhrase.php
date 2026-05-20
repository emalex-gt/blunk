<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantFelPhrase extends Model
{
    protected $fillable = [
        'business_id',
        'tenant_fel_setting_id',
        'data_identifier',
        'phrase_type',
        'scenario_code',
        'resolution_number',
        'resolution_date',
        'type_data',
        'type_value',
        'scenario_data',
        'scenario_value',
    ];

    protected $casts = [
        'resolution_date' => 'date',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(TenantFelSetting::class, 'tenant_fel_setting_id');
    }
}
