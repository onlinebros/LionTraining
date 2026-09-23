<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name'         => 'free_member',
                'display_name' => 'Free Member',
                'description'  => 'Standard free account. Access to basic features and referral system.',
                'is_admin'     => false,
                'level'        => 0,
            ],
            [
                'name'         => 'paid_member',
                'display_name' => 'Paid Member',
                'description'  => 'Active paid subscription. Full access to training features.',
                'is_admin'     => false,
                'level'        => 1,
            ],
            [
                'name'         => 'product_partner',
                'display_name' => 'Product Partner',
                'description'  => 'A vendor whose products we sell. Sees their own sales, pipeline and settlement — nothing else.',
                'is_admin'     => false,
                'level'        => 5,
            ],
            [
                'name'         => 'support_admin',
                'display_name' => 'Support Admin',
                'description'  => 'Customer support staff. Can view and manage members, cannot modify admins.',
                'is_admin'     => true,
                'level'        => 10,
            ],
            [
                'name'         => 'super_admin',
                'display_name' => 'Super Admin',
                'description'  => 'Full system access. Can manage all users, roles, and system settings.',
                'is_admin'     => true,
                'level'        => 99,
            ],
        ];

        foreach ($roles as $role) {
            \App\Models\Role::updateOrCreate(['name' => $role['name']], $role);
        }
    }
}
