<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceAnnouncement extends Model
{
    protected $fillable = [
        'title',
        'message',
        'visible_from',
        'starts_at',
        'ends_at',
        'expected_downtime',
        'enabled',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'visible_from' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'enabled' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeVisible(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('enabled', true)
            ->where('visible_from', '<=', $at)
            ->where('ends_at', '>', $at);
    }

    public function statusAt(?CarbonInterface $at = null): string
    {
        $at ??= now();

        if (! $this->enabled) {
            return 'disabled';
        }

        if ($this->ends_at->lte($at)) {
            return 'expired';
        }

        return $this->starts_at->gt($at) ? 'scheduled' : 'active';
    }

    /**
     * @return array<string, string|null>
     */
    public function publicPayload(string $timezone): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'expected_downtime' => $this->expected_downtime,
            'starts_at' => $this->starts_at->copy()->timezone($timezone)->format('Y-m-d H:i'),
            'ends_at' => $this->ends_at->copy()->timezone($timezone)->format('Y-m-d H:i'),
            'timezone' => $timezone,
            'status' => $this->statusAt(),
        ];
    }
}
