<?php

namespace App\Support\Reports;

class ReportMoney
{
    public static function round(float|int|string|null $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
