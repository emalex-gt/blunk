<?php

namespace App\Support;

use App\Models\User;

class AuthRedirect
{
    public static function pathFor(?User $user): string
    {
        if (! $user) {
            return route('login', absolute: false);
        }

        if ($user->is_super_admin) {
            return route('super-admin.dashboard', absolute: false);
        }

        if (Permissions::userHas($user, Permissions::POS_VIEW)) {
            return route('dashboard', absolute: false);
        }

        return route('profile.edit', absolute: false);
    }
}
