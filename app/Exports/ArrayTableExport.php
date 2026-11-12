<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class ArrayTableExport extends DefaultValueBinder implements Export, FromArray, ShouldAutoSize, WithCustomValueBinder, WithTitle
{
    public function __construct(
        private readonly array $rows,
        private readonly string $title = 'Reporte',
        private readonly array $quantityColumnIndexes = [],
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

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($cell->getRow() >= 8
            && in_array(Coordinate::columnIndexFromString($cell->getColumn()), $this->quantityColumnIndexes, true)
            && is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
