<?php

namespace App\Support\Reports;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ReportDateRange
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $dateFrom,
        public readonly string $dateTo,
    ) {
    }

    public static function monthToDate(Request $request, ?Business $business): self
    {
        $timezone = tenantTimezone($business);
        $today = now($timezone);

        return self::fromRequest(
            $request,
            $business,
            $today->copy()->startOfMonth()->toDateString(),
            $today->toDateString(),
        );
    }

    public static function daily(Request $request, ?Business $business): self
    {
        $timezone = tenantTimezone($business);
        $date = $request->query('date', now($timezone)->toDateString());

        return self::fromRequest($request, $business, (string) $date, (string) $date);
    }

    public static function fromRequest(Request $request, ?Business $business, string $defaultFrom, string $defaultTo): self
    {
        $timezone = tenantTimezone($business);
        $dateFrom = Carbon::parse((string) $request->query('date_from', $defaultFrom), $timezone)->toDateString();
        $dateTo = Carbon::parse((string) $request->query('date_to', $defaultTo), $timezone)->toDateString();

        $startLocal = Carbon::parse($dateFrom, $timezone)->startOfDay();
        $endLocal = Carbon::parse($dateTo, $timezone)->endOfDay();

        if ($endLocal->lt($startLocal)) {
            [$startLocal, $endLocal] = [$endLocal->copy()->startOfDay(), $startLocal->copy()->endOfDay()];
            [$dateFrom, $dateTo] = [$startLocal->toDateString(), $endLocal->toDateString()];
        }

        if ($startLocal->diffInMonths($endLocal) > 3 || $startLocal->copy()->addMonthsNoOverflow(3)->endOfDay()->lt($endLocal)) {
            throw ValidationException::withMessages([
                'date_from' => 'El rango máximo permitido es de 3 meses.',
                'date_to' => 'El rango máximo permitido es de 3 meses.',
            ]);
        }

        return new self($startLocal->utc(), $endLocal->utc(), $dateFrom, $dateTo);
    }
}
