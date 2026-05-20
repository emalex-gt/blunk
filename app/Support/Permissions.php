<?php

namespace App\Support;

use App\Models\User;

class Permissions
{
    public const FEL_DOCUMENTS_VIEW = 'fel.documents.view';
    public const SALES_DISCOUNT_APPLY = 'sales.discount.apply';
    public const BRANCHES_VIEW = 'branches.view';
    public const BRANCHES_MANAGE = 'branches.manage';
    public const INVENTORY_TRANSFERS_VIEW = 'inventory.transfers.view';
    public const INVENTORY_TRANSFERS_CREATE = 'inventory.transfers.create';

    public static function forUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        if ($user->is_super_admin || in_array($user->role, ['owner', 'admin'], true)) {
            return [
                self::FEL_DOCUMENTS_VIEW,
                self::SALES_DISCOUNT_APPLY,
                self::BRANCHES_VIEW,
                self::BRANCHES_MANAGE,
                self::INVENTORY_TRANSFERS_VIEW,
                self::INVENTORY_TRANSFERS_CREATE,
            ];
        }

        return [];
    }

    public static function userHas(?User $user, string $permission): bool
    {
        return in_array($permission, self::forUser($user), true);
    }
}
