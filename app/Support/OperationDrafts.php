<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\OperationDraft;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationDrafts
{
    public const PAYLOAD_VERSION = 1;

    public static function allowedTypes(): array
    {
        return [
            OperationDraft::TYPE_POS_SALE,
            OperationDraft::TYPE_PURCHASE,
            OperationDraft::TYPE_TRANSFER,
        ];
    }

    public static function assertTypePermission(User $user, string $type): void
    {
        [$module, $permission] = match ($type) {
            OperationDraft::TYPE_POS_SALE => ['pos', Permissions::POS_VIEW],
            OperationDraft::TYPE_PURCHASE => ['purchases', Permissions::PURCHASES_CREATE],
            OperationDraft::TYPE_TRANSFER => ['branches', Permissions::INVENTORY_TRANSFERS_CREATE],
            default => [null, null],
        };

        abort_unless($module && module_enabled($module) && Permissions::userHas($user, $permission), 403);
    }

    public static function canManageOthers(User $user): bool
    {
        return (bool) $user->is_super_admin || Permissions::userHas($user, Permissions::USERS_VIEW);
    }

    public static function activeQueryFor(Request $request, string $type): Builder
    {
        $user = $request->user();
        self::assertTypePermission($user, $type);

        $query = OperationDraft::query()
            ->where('business_id', currentBusinessId())
            ->where('type', $type)
            ->where('status', OperationDraft::STATUS_ACTIVE);

        if (! self::canManageOthers($user)) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    public static function normalizePayloadVersion(mixed $version): int
    {
        $version = (int) ($version ?: self::PAYLOAD_VERSION);

        return $version > 0 ? $version : self::PAYLOAD_VERSION;
    }

    public static function validateBusinessReference(?int $id, string $model, string $field): ?int
    {
        if (! $id) {
            return null;
        }

        $belongs = match ($model) {
            Branch::class => Branch::query()->where('business_id', currentBusinessId())->whereKey($id)->exists(),
            Customer::class => Customer::query()->where('business_id', currentBusinessId())->whereKey($id)->exists(),
            Supplier::class => Supplier::query()->where('business_id', currentBusinessId())->whereKey($id)->exists(),
            default => false,
        };

        if (! $belongs) {
            throw ValidationException::withMessages([
                $field => 'El dato seleccionado no pertenece a esta empresa.',
            ]);
        }

        return $id;
    }

    public static function markConverted(?int $draftId, string $type, string $convertedType, int $convertedId, Request $request): void
    {
        if (! $draftId) {
            return;
        }

        DB::transaction(function () use ($draftId, $type, $convertedType, $convertedId, $request) {
            $draft = OperationDraft::query()
                ->where('business_id', currentBusinessId())
                ->where('type', $type)
                ->where('status', OperationDraft::STATUS_ACTIVE)
                ->lockForUpdate()
                ->find($draftId);

            if (! $draft) {
                return;
            }

            $user = $request->user();
            if ((int) $draft->user_id !== (int) $user->id && ! self::canManageOthers($user)) {
                return;
            }

            $draft->update([
                'status' => OperationDraft::STATUS_CONVERTED,
                'converted_type' => $convertedType,
                'converted_id' => $convertedId,
                'converted_at' => now(),
                'last_used_at' => now(),
            ]);
        });
    }
}
