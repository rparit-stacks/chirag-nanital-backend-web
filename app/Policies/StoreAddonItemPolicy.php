<?php

namespace App\Policies;

use App\Enums\DefaultSystemRolesEnum;
use App\Enums\SellerPermissionEnum;
use App\Models\StoreAddonItem;
use App\Models\User;
use App\Traits\ChecksPermissions;

class StoreAddonItemPolicy
{
    use ChecksPermissions;

    /**
     * Determine whether the user can view the store addon inventory listing.
     */
    public function viewAny(User $user): bool
    {
        if ($user->seller() === null) {
            return false;
        }

        return $user->hasRole(DefaultSystemRolesEnum::SELLER())
            || $this->hasPermission(SellerPermissionEnum::STORE_ADDON_ITEM_VIEW());
    }

    /**
     * Determine whether the user can view a single store addon item row.
     */
    public function view(User $user, StoreAddonItem $row): bool
    {
        return $this->isOwnedBySeller($user, $row)
            && (
                $user->hasRole(DefaultSystemRolesEnum::SELLER())
                || $this->hasPermission(SellerPermissionEnum::STORE_ADDON_ITEM_VIEW())
            );
    }

    /**
     * Determine whether the user can create store addon inventory rows.
     */
    public function create(User $user): bool
    {
        if ($user->seller() === null) {
            return false;
        }

        return $user->hasRole(DefaultSystemRolesEnum::SELLER())
            || $this->hasPermission(SellerPermissionEnum::STORE_ADDON_ITEM_CREATE());
    }

    /**
     * Determine whether the user can update a store addon item row.
     */
    public function update(User $user, StoreAddonItem $row): bool
    {
        return $this->isOwnedBySeller($user, $row)
            && (
                $user->hasRole(DefaultSystemRolesEnum::SELLER())
                || $this->hasPermission(SellerPermissionEnum::STORE_ADDON_ITEM_EDIT())
            );
    }

    /**
     * Determine whether the user can delete a store addon item row.
     */
    public function delete(User $user, StoreAddonItem $row): bool
    {
        return $this->isOwnedBySeller($user, $row)
            && (
                $user->hasRole(DefaultSystemRolesEnum::SELLER())
                || $this->hasPermission(SellerPermissionEnum::STORE_ADDON_ITEM_DELETE())
            );
    }

    /**
     * Resource ownership guard — the store AND the addon item's group must both
     * belong to the authenticated seller.
     */
    protected function isOwnedBySeller(User $user, StoreAddonItem $row): bool
    {
        $seller = $user->seller();
        if ($seller === null) {
            return false;
        }

        $row->loadMissing(['store', 'addonItem.group']);

        $storeSellerId = (int) ($row->store?->seller_id ?? 0);
        $groupSellerId = (int) ($row->addonItem?->group?->seller_id ?? 0);

        return $storeSellerId === (int) $seller->id
            && $groupSellerId === (int) $seller->id;
    }
}
