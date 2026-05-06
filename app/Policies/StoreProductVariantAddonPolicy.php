<?php

namespace App\Policies;

use App\Enums\DefaultSystemRolesEnum;
use App\Enums\SellerPermissionEnum;
use App\Models\StoreProductVariantAddon;
use App\Models\User;
use App\Traits\ChecksPermissions;

class StoreProductVariantAddonPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        if ($user->seller() === null) {
            return false;
        }

        return $user->hasRole(DefaultSystemRolesEnum::SELLER())
            || $this->hasPermission(SellerPermissionEnum::PRODUCT_ADDON_VIEW());
    }

    public function create(User $user): bool
    {
        if ($user->seller() === null) {
            return false;
        }

        return $user->hasRole(DefaultSystemRolesEnum::SELLER())
            || $this->hasPermission(SellerPermissionEnum::PRODUCT_ADDON_CREATE());
    }

    public function update(User $user, StoreProductVariantAddon $row): bool
    {
        return $this->isOwnedBySeller($user, $row)
            && (
                $user->hasRole(DefaultSystemRolesEnum::SELLER())
                || $this->hasPermission(SellerPermissionEnum::PRODUCT_ADDON_EDIT())
            );
    }

    public function delete(User $user, StoreProductVariantAddon $row): bool
    {
        return $this->isOwnedBySeller($user, $row)
            && (
                $user->hasRole(DefaultSystemRolesEnum::SELLER())
                || $this->hasPermission(SellerPermissionEnum::PRODUCT_ADDON_DELETE())
            );
    }

    protected function isOwnedBySeller(User $user, StoreProductVariantAddon $row): bool
    {
        $seller = $user->seller();
        if ($seller === null) {
            return false;
        }

        return (int) ($row->store?->seller_id) === (int) $seller->id;
    }
}
