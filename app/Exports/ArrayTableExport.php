<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class ArrayTableExport implements FromArray, ShouldAutoSize, WithTitle
{
    public function __construct(
        private readonly array $rows,
        private readonly string $title = 'Reporte',
    ) {
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);
    }
}
