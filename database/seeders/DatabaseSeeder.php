<?php

namespace Database\Seeders;

use App\Enums\DefaultSystemRolesEnum;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        // use this in update file
//        $this->call([
//            SystemVendorTypeSeeder::class,
//            BackfillWalletTypesSeeder::class, // one-shot: relabels legacy wallets to seller/delivery_boy
//        ]);

        // use this in fresh install
        $this->call([
            CountriesSeeder::class,
            DefaultRolesSeeder::class,
            BackfillWalletTypesSeeder::class,
        ]);

//        super_admin
//        try {
//            $user = User::find(1);
//            $user->assignRole(DefaultSystemRolesEnum::SUPER_ADMIN());
//
//        } catch (\Throwable $th) {
//            dd($th->getMessage());
//        }
    }
}
