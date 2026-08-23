<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FinalConsumerAuditor
{
    private const CUSTOMER_RELATIONS = [
        'sales' => 'customer_id',
        'credit_receipts' => 'customer_id',
        'route_zone_customers' => 'customer_id',
        'route_visits' => 'customer_id',
        'pre_sales' => 'customer_id',
        'customer_account_movements' => 'customer_id',
        'customer_credit_payments' => 'customer_id',
        'operation_drafts' => 'customer_id',
    ];

    public function audit(array $options): array
    {
        $business = $this->business($options);
        $genericCustomers = $this->genericFinalConsumers($business->id);
        $plan = $this->mergePlan($business->id, $genericCustomers);
        $realTaxDuplicates = $this->realTaxDuplicates($business->id);
        $cfCustomers = Customer::query()
            ->where('business_id', $business->id)
            ->where(function ($query) {
                $query->where('normalized_tax_id', 'CF')->orWhereNull('normalized_tax_id');
            })
            ->orderBy('id')
            ->get();

        $reportPath = null;
        if ((bool) ($options['report'] ?? false)) {
            $reportPath = $this->writeReport($business->id, $plan, $realTaxDuplicates, $cfCustomers);
        }

        return [
            'business_id' => $business->id,
            'generic_cf_duplicate_groups' => $genericCustomers->count() > 1 ? 1 : 0,
            'generic_cf_customers' => $genericCustomers->count(),
            'real_tax_id_duplicate_groups' => $realTaxDuplicates->count(),
            'real_tax_id_duplicates' => $realTaxDuplicates->sum(fn (array $group) => count($group['customer_ids'])),
            'cf_customers' => $cfCustomers->count(),
            'report_path' => $reportPath,
            'merge_preview' => $plan,
        ];
    }

    public function merge(array $options): array
    {
        $business = $this->business($options);
        $confirm = (bool) ($options['confirm'] ?? false);
        $plan = $this->mergePlan($business->id, $this->genericFinalConsumers($business->id));
        $result = ['mode' => $confirm ? 'confirm' : 'dry-run', ...$plan];

        if ($plan['duplicate_customer_ids'] === []) {
            return $result;
        }

        if (! $confirm) {
            return $result;
        }

        if ($plan['blocking_relations'] !== []) {
            throw new RuntimeException('La fusión requiere revisión manual: '.implode(', ', $plan['blocking_relations']).'.');
        }

        DB::transaction(function () use ($business, $plan): void {
            Business::query()->lockForUpdate()->findOrFail($business->id);
            $canonicalId = $plan['canonical_customer_id'];
            $duplicateIds = $plan['duplicate_customer_ids'];

            Customer::query()
                ->where('business_id', $business->id)
                ->whereKey($canonicalId)
                ->lockForUpdate()
                ->firstOrFail();
            Customer::query()
                ->where('business_id', $business->id)
                ->whereIn('id', $duplicateIds)
                ->lockForUpdate()
                ->get();

            foreach (self::CUSTOMER_RELATIONS as $table => $column) {
                $this->moveReference($table, $column, $business->id, $duplicateIds, $canonicalId);
            }

            if (Schema::hasTable('credit_customer_transfers')) {
                foreach (['from_customer_id', 'to_customer_id'] as $column) {
                    DB::table('credit_customer_transfers')
                        ->where('business_id', $business->id)
                        ->whereIn($column, $duplicateIds)
                        ->update([$column => $canonicalId, 'updated_at' => now()]);
                }
            }

            if (Schema::hasTable('customer_credit_accounts')) {
                $this->moveReference('customer_credit_accounts', 'customer_id', $business->id, $duplicateIds, $canonicalId);
            }

            Customer::query()
                ->where('business_id', $business->id)
                ->whereIn('id', $duplicateIds)
                ->update([
                    'merged_into_customer_id' => $canonicalId,
                    'merged_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return $result;
    }

    private function business(array $options): Business
    {
        $businessId = (int) ($options['business'] ?? 0);
        if ($businessId <= 0) {
            throw new RuntimeException('Debes indicar --business=ID.');
        }

        return Business::query()->findOrFail($businessId);
    }

    private function genericFinalConsumers(int $businessId): Collection
    {
        return Customer::query()
            ->where('business_id', $businessId)
            ->whereNull('merged_into_customer_id')
            ->where('normalized_tax_id', 'CF')
            ->withCount('sales')
            ->orderBy('id')
            ->get()
            ->filter(fn (Customer $customer) => CustomerIdentity::isGenericFinalConsumer(
                $customer->normalized_tax_id,
                $customer->name,
                $customer->only(['commercial_name', 'contact_name', 'address', 'phone', 'postal_code', 'department', 'municipality']),
            ))
            ->values();
    }

    private function mergePlan(int $businessId, Collection $genericCustomers): array
    {
        if ($genericCustomers->count() < 2) {
            return [
                'canonical_customer_id' => null,
                'duplicate_customer_ids' => [],
                'sales_to_move' => 0,
                'credit_receipts_to_move' => 0,
                'other_relations_to_move' => [],
                'customers_to_deactivate' => [],
                'blocking_relations' => [],
            ];
        }

        $canonical = $genericCustomers
            ->sort(fn (Customer $left, Customer $right) => (($left->sales_count > 0 ? 0 : 1) <=> ($right->sales_count > 0 ? 0 : 1)) ?: ($left->id <=> $right->id))
            ->first();
        $duplicateIds = $genericCustomers->where('id', '!=', $canonical->id)->pluck('id')->all();
        $counts = $this->relationCounts($businessId, $duplicateIds);
        $blocking = $this->blockingRelations($businessId, $canonical->id, $duplicateIds);

        return [
            'canonical_customer_id' => $canonical->id,
            'duplicate_customer_ids' => $duplicateIds,
            'sales_to_move' => $counts['sales'] ?? 0,
            'credit_receipts_to_move' => $counts['credit_receipts'] ?? 0,
            'other_relations_to_move' => collect($counts)
                ->except(['sales', 'credit_receipts'])
                ->filter(fn (int $count) => $count > 0)
                ->all(),
            'customers_to_deactivate' => $duplicateIds,
            'blocking_relations' => $blocking,
        ];
    }

    private function relationCounts(int $businessId, array $customerIds): array
    {
        $counts = [];
        foreach (self::CUSTOMER_RELATIONS as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                $counts[$table] = DB::table($table)
                    ->where('business_id', $businessId)
                    ->whereIn($column, $customerIds)
                    ->count();
            }
        }

        if (Schema::hasTable('credit_customer_transfers')) {
            $counts['credit_customer_transfers'] = DB::table('credit_customer_transfers')
                ->where('business_id', $businessId)
                ->where(function ($query) use ($customerIds) {
                    $query->whereIn('from_customer_id', $customerIds)->orWhereIn('to_customer_id', $customerIds);
                })
                ->count();
        }

        if (Schema::hasTable('customer_credit_accounts')) {
            $counts['customer_credit_accounts'] = DB::table('customer_credit_accounts')
                ->where('business_id', $businessId)
                ->whereIn('customer_id', $customerIds)
                ->count();
        }

        return $counts;
    }

    private function blockingRelations(int $businessId, int $canonicalId, array $duplicateIds): array
    {
        $blocking = [];

        if (Schema::hasTable('customer_credit_accounts')) {
            $canonicalHasAccount = DB::table('customer_credit_accounts')
                ->where('business_id', $businessId)
                ->where('customer_id', $canonicalId)
                ->exists();
            $duplicateAccountCount = DB::table('customer_credit_accounts')
                ->where('business_id', $businessId)
                ->whereIn('customer_id', $duplicateIds)
                ->count();

            if (($canonicalHasAccount && $duplicateAccountCount > 0) || $duplicateAccountCount > 1) {
                $blocking[] = 'customer_credit_accounts';
            }
        }

        foreach ([
            'route_zone_customers' => ['route_zone_id'],
            'route_visits' => ['route_work_day_id'],
        ] as $table => $keys) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $allKeys = DB::table($table)
                ->where('business_id', $businessId)
                ->whereIn('customer_id', [$canonicalId, ...$duplicateIds])
                ->get($keys)
                ->map(fn (object $row) => implode('|', array_map(fn (string $key) => (string) $row->{$key}, $keys)))
                ->all();

            if (count($allKeys) !== count(array_unique($allKeys))) {
                $blocking[] = $table;
            }
        }

        if (Schema::hasTable('credit_customer_transfers')
            && DB::table('credit_customer_transfers')
                ->where('business_id', $businessId)
                ->whereIn('from_customer_id', [$canonicalId, ...$duplicateIds])
                ->whereIn('to_customer_id', [$canonicalId, ...$duplicateIds])
                ->exists()) {
            $blocking[] = 'credit_customer_transfers';
        }

        return $blocking;
    }

    private function moveReference(string $table, string $column, int $businessId, array $duplicateIds, int $canonicalId): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->where('business_id', $businessId)
            ->whereIn($column, $duplicateIds)
            ->update([$column => $canonicalId, 'updated_at' => now()]);
    }

    private function realTaxDuplicates(int $businessId): Collection
    {
        return Customer::query()
            ->where('business_id', $businessId)
            ->whereNull('merged_into_customer_id')
            ->whereNotNull('normalized_tax_id')
            ->where('normalized_tax_id', '!=', 'CF')
            ->select('normalized_tax_id')
            ->selectRaw('COUNT(*) as customer_count')
            ->groupBy('normalized_tax_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(function (Customer $row) use ($businessId): array {
                $customers = Customer::query()
                    ->where('business_id', $businessId)
                    ->where('normalized_tax_id', $row->normalized_tax_id)
                    ->orderBy('id')
                    ->get(['id', 'name', 'doc_number']);

                return [
                    'normalized_tax_id' => $row->normalized_tax_id,
                    'customer_ids' => $customers->pluck('id')->all(),
                    'customer_names' => $customers->pluck('name')->all(),
                    'count' => $customers->count(),
                ];
            })
            ->values();
    }

    private function writeReport(int $businessId, array $plan, Collection $realTaxDuplicates, Collection $cfCustomers): string
    {
        $directory = storage_path('app/customer-audits/'.now()->format('Ymd-His')."-business-{$businessId}");
        File::ensureDirectoryExists($directory);

        $this->writeCsv($directory.'/generic_cf_duplicates.csv', [[
            'canonical_customer_id' => $plan['canonical_customer_id'],
            'duplicate_customer_ids' => implode('|', $plan['duplicate_customer_ids']),
            'sales_to_move' => $plan['sales_to_move'],
            'credit_receipts_to_move' => $plan['credit_receipts_to_move'],
            'other_relations_to_move' => json_encode($plan['other_relations_to_move']),
            'blocking_relations' => implode('|', $plan['blocking_relations']),
        ]]);
        $this->writeCsv($directory.'/real_tax_id_duplicates.csv', $realTaxDuplicates->all());
        $this->writeCsv($directory.'/cf_customers.csv', $cfCustomers->map(fn (Customer $customer) => [
            'customer_id' => $customer->id,
            'name' => $customer->name,
            'doc_number' => $customer->doc_number,
            'classification' => CustomerIdentity::isGenericFinalConsumer(
                $customer->normalized_tax_id,
                $customer->name,
                $customer->only(['commercial_name', 'contact_name', 'address', 'phone', 'postal_code', 'department', 'municipality']),
            ) ? 'generic' : 'personalized',
            'merged_into_customer_id' => $customer->merged_into_customer_id,
        ])->all());
        $this->writeCsv($directory.'/summary.csv', [[
            'business_id' => $businessId,
            'generic_cf_duplicate_groups' => $plan['duplicate_customer_ids'] === [] ? 0 : 1,
            'real_tax_id_duplicate_groups' => $realTaxDuplicates->count(),
            'sales_to_move' => $plan['sales_to_move'],
            'credit_receipts_to_move' => $plan['credit_receipts_to_move'],
            'blocking_relations' => implode('|', $plan['blocking_relations']),
        ]]);

        return $directory;
    }

    private function writeCsv(string $path, array $rows): void
    {
        $headers = $rows === [] ? [] : array_keys($rows[0]);
        $stream = fopen('php://temp', 'w+');
        if ($headers !== []) {
            fputcsv($stream, $headers);
            foreach ($rows as $row) {
                fputcsv($stream, array_map(fn (mixed $value) => is_array($value) ? json_encode($value) : $value, $row));
            }
        }
        rewind($stream);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, stream_get_contents($stream));
        fclose($stream);
    }
}
