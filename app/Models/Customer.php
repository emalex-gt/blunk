<?php

namespace App\Models;

use App\Support\CustomerIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'commercial_name',
        'contact_name',
        'doc_type',
        'doc_number',
        'normalized_tax_id',
        'tax_condition',
        'address',
        'postal_code',
        'municipality',
        'department',
        'phone',
        'country',
        'is_final_consumer',
        'name_locked',
        'tax_lookup_payload',
        'tax_lookup_verified_at',
        'merged_into_customer_id',
        'merged_at',
    ];

    protected $casts = [
        'is_final_consumer' => 'boolean',
        'name_locked' => 'boolean',
        'tax_lookup_payload' => 'array',
        'tax_lookup_verified_at' => 'datetime',
        'merged_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $customer): void {
            $customer->normalized_tax_id = CustomerIdentity::normalizeTaxId($customer->doc_number);
        });
    }

    public static function getOrCreateGenericFinalConsumer(Business|int $business): self
    {
        $businessId = $business instanceof Business ? $business->id : $business;

        return DB::transaction(function () use ($businessId): self {
            Business::query()->lockForUpdate()->findOrFail($businessId);

            $candidates = self::query()
                ->where('business_id', $businessId)
                ->whereNull('merged_into_customer_id')
                ->where('normalized_tax_id', 'CF')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (self $customer) => CustomerIdentity::isGenericFinalConsumer(
                    $customer->normalized_tax_id,
                    $customer->name,
                    $customer->only(['commercial_name', 'contact_name', 'address', 'phone', 'postal_code', 'department', 'municipality']),
                ));

            if ($candidates->isNotEmpty()) {
                return $candidates
                    ->sortBy(fn (self $customer) => $customer->sales()->exists() ? 0 : 1)
                    ->first();
            }

            return self::query()->create([
                'business_id' => $businessId,
                'name' => 'Consumidor Final',
                'commercial_name' => null,
                'contact_name' => null,
                'doc_type' => 'CF',
                'doc_number' => 'CF',
                'tax_condition' => null,
                'address' => null,
                'postal_code' => null,
                'municipality' => null,
                'department' => null,
                'phone' => null,
                'country' => 'GT',
                'is_final_consumer' => true,
                'name_locked' => false,
                'tax_lookup_verified_at' => null,
            ]);
        });
    }

    public static function findOrCreateByNormalizedTaxId(Business|int $business, array $attributes): self
    {
        $businessId = $business instanceof Business ? $business->id : $business;
        $normalizedTaxId = CustomerIdentity::normalizeTaxId($attributes['doc_number'] ?? null);

        if (! CustomerIdentity::isRealTaxId($normalizedTaxId)) {
            throw ValidationException::withMessages([
                'customer.doc_number' => 'Debes ingresar un NIT válido.',
            ]);
        }

        try {
            return DB::transaction(function () use ($businessId, $normalizedTaxId, $attributes): self {
                Business::query()->lockForUpdate()->findOrFail($businessId);

                if ($customer = self::findByNormalizedTaxIdForUpdate($businessId, $normalizedTaxId)) {
                    return $customer;
                }

                return self::query()->create([
                    ...$attributes,
                    'business_id' => $businessId,
                    'doc_number' => $normalizedTaxId,
                    'normalized_tax_id' => $normalizedTaxId,
                ]);
            });
        } catch (QueryException $exception) {
            if (! self::isUniqueViolation($exception)) {
                throw $exception;
            }

            $customer = DB::transaction(fn (): ?self => self::findByNormalizedTaxIdForUpdate($businessId, $normalizedTaxId));

            if ($customer) {
                return $customer;
            }

            throw ValidationException::withMessages([
                'customer.doc_number' => 'Ya existe un cliente con este NIT.',
            ]);
        }
    }

    private static function findByNormalizedTaxIdForUpdate(int $businessId, string $normalizedTaxId): ?self
    {
        $customer = self::query()
            ->where('business_id', $businessId)
            ->whereNull('merged_into_customer_id')
            ->where('normalized_tax_id', $normalizedTaxId)
            ->lockForUpdate()
            ->first();

        if ($customer) {
            return $customer;
        }

        $legacyCustomer = self::query()
            ->where('business_id', $businessId)
            ->whereNull('merged_into_customer_id')
            ->whereNull('normalized_tax_id')
            ->lockForUpdate()
            ->get()
            ->first(fn (self $candidate) => CustomerIdentity::normalizeTaxId($candidate->doc_number) === $normalizedTaxId);

        if ($legacyCustomer) {
            $legacyCustomer->forceFill(['normalized_tax_id' => $normalizedTaxId])->save();
        }

        return $legacyCustomer;
    }

    private static function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->commercial_name ?: $this->name;
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function creditAccount(): HasOne
    {
        return $this->hasOne(CustomerCreditAccount::class);
    }
}
