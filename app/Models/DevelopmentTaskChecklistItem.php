<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DevelopmentTaskChecklistItem extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['is_completed' => 'boolean', 'completed_at' => 'datetime'];
}
