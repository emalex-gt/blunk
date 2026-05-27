<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BusinessCounter
{
    public static function next(Business|int $business, string $key): int
    {
        $businessId = $business instanceof Business ? (int) $business->id : (int) $business;

        if ($businessId <= 0 || trim($key) === '') {
            throw new RuntimeException('Invalid business counter input.');
        }

        return DB::transaction(function () use ($businessId, $key) {
            $counter = DB::table('business_counters')
                ->where('business_id', $businessId)
                ->where('counter_key', $key)
                ->lockForUpdate()
                ->first();

            if (! $counter) {
                try {
                    DB::table('business_counters')->insert([
                        'business_id' => $businessId,
                        'counter_key' => $key,
                        'current_number' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (QueryException) {
                    // Another request may have created the counter. Re-read it with the row lock.
                }

                $counter = DB::table('business_counters')
                    ->where('business_id', $businessId)
                    ->where('counter_key', $key)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $counter) {
                throw new RuntimeException('Could not create business counter.');
            }

            $next = ((int) $counter->current_number) + 1;

            DB::table('business_counters')
                ->where('id', $counter->id)
                ->update([
                    'current_number' => $next,
                    'updated_at' => now(),
                ]);

            return $next;
        });
    }
}
