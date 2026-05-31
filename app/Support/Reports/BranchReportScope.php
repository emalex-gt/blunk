<?php

namespace App\Support\Reports;

use App\Models\Branch;
use App\Support\BranchInventory;

class BranchReportScope
{
    public function __construct(
        public readonly int $businessId,
        public readonly Branch $branch,
    ) {
    }

    public static function current(int $businessId): self
    {
        return new self($businessId, BranchInventory::activeBranch($businessId));
    }

    public function apply($query, string $column = 'branch_id'): void
    {
        $query->where($column, $this->branch->id);
    }

    public function payload(): array
    {
        return [
            'id' => $this->branch->id,
            'name' => $this->branch->name,
            'code' => $this->branch->code,
        ];
    }
}
