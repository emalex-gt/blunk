<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevelopmentTaskActivity extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['previous_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
