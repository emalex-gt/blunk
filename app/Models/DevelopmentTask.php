<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DevelopmentTask extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['version' => 'integer', 'sort_order' => 'integer', 'completed_at' => 'datetime', 'deployed_at' => 'datetime', 'production_verified_at' => 'datetime', 'archived_at' => 'datetime'];

    protected static function booted(): void
    {
        static::created(function (self $task) {
            $task->code = 'BLK-'.str_pad((string) $task->id, 4, '0', STR_PAD_LEFT);
            $task->saveQuietly();
        });
    }

    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function checklist(): HasMany { return $this->hasMany(DevelopmentTaskChecklistItem::class)->orderBy('sort_order')->orderBy('id'); }
    public function activities(): HasMany { return $this->hasMany(DevelopmentTaskActivity::class); }
}
