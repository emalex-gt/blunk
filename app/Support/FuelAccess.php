<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Services\BranchAccessService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** Never infer a fuel tenant from the browser session. Bearer tokens only. */
final class FuelAccess
{
    public static function businessFor(User $user): Business
    {
        abort_unless($user->is_active && ! $user->is_super_admin && $user->business_id, 403);

        $business = Business::query()->with('latestSubscription')->findOrFail((int) $user->business_id);
        abort_unless($business->is_active && $business->country === 'GT', 403);
        abort_if(in_array($business->latestSubscription?->status, ['cancelled', 'expired', 'paused'], true), 403);

        return $business;
    }

    public static function requireContext(Request $request, string $permission, string $ability): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $token = $user->currentAccessToken();
        // Reject cookie-authenticated, wildcard and unrelated Sanctum tokens.
        abort_unless($token instanceof PersonalAccessToken, 401);
        abort_unless(in_array($ability, (array) $token->abilities, true), 403);
        abort_unless(Permissions::userHas($user, $permission), 403);

        $business = self::businessFor($user);
        abort_unless(in_array('fuel:tenant:'.$business->id, (array) $token->abilities, true), 403);

        $rawBranch = (string) $request->header('X-Blunk-Branch', '');
        abort_unless(preg_match('/^[1-9][0-9]{0,18}$/D', $rawBranch) === 1, 422, 'X-Blunk-Branch es obligatorio.');
        $branchId = (int) $rawBranch;
        abort_unless($branchId > 0 && in_array('fuel:branch:'.$branchId, (array) $token->abilities, true), 403);

        $branch = app(BranchAccessService::class)->assertCanAccessBranch($user, $branchId, (int) $business->id);
        abort_unless($branch->is_active && (int) $branch->business_id === (int) $business->id, 403);

        return [$user, $business, $branch, $token];
    }

    public static function authorizedBranches(User $user, Business $business): \Illuminate\Support\Collection
    {
        return app(BranchAccessService::class)->authorizedBranches($user, $business);
    }
}
