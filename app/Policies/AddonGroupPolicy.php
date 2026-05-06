<?php

namespace App\Policies;

use App\Enums\DefaultSystemRolesEnum;
use App\Enums\SellerPermissionEnum;
use App\Models\AddonGroup;
use App\Models\User;
use App\Traits\ChecksPermissions;

class AddonGroupPolicy
{
    use ChecksPermissions;

    /**
     * Determine whether the user can view the addon group listing.
     */
    public function viewAny(User $user): bool
    {
        if ($user->seller() === null) {
            return false;
        }

        return $user->hasRole(DefaultSystemRolesEnum::SELLER())
            || $this->hasPermission(SellerPermissionEnum::ADDON_GROUP_VIEW());
    }

    /**
     * Determine whether the user can view the addon group.
     */
    public function view(User $user, AddonGroup $addonGroup): bool
    {
        return $this->isOwnedBySeller($user, $addonGroup)
            && (
                $user->hasRole(DefaultSystemRolesEnum::SELLER())
                || $this->hasPermission(SellerPermissionEnum::ADDON_GROUP_VIEW())
            );
    }

    /**
     * Determine whether the user can create addon groups.
     */
    public function create(User $user): bool
    {
        if ($user->seller() === null) {
            return false;
        }

        return $user->hasRole(DefaultSystemRolesEnum::SELLER())
            || $this->hasPermission(SellerPermissionEnum::ADDON_GROUP_CREATE());
    }

    /**
     * Determine whether the user can update the addon group.
     */
    public function update(User $user, AddonGroup $addonGroup): bool
    {
        return $this->isOwnedBySeller($user, $addonGroup)
            && (
                $user->hasRole(DefaultSystemRolesEnum::SELLER())
                || $this->hasPermission(SellerPermissionEnum::ADDON_GROUP_EDIT())
            );
    }

    /**
     * Determine whether the user can delete the addon group.
     */
    public function delete(User $user, AddonGroup $addonGroup): bool
    {
        return $this->isOwnedBySeller($user, $addonGroup)
            && (
                $user->hasRole(DefaultSystemRolesEnum::SELLER())
                || $this->hasPermission(SellerPermissionEnum::ADDON_GROUP_DELETE())
            );
    }

    /**
     * Resource ownership guard - sellers may only manage their own addon groups.
     */
    protected function isOwnedBySeller(User $user, AddonGroup $addonGroup): bool
    {
        $seller = $user->seller();
        if ($seller === null) {
            return false;
        }

        return (int) $addonGroup->seller_id === (int) $seller->id;
    }
}
