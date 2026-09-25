<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Create super_admin role (Spatie)
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        // Create super admin user
        $admin = User::updateOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'name'       => 'Super Admin',
                'username'   => 'superadmin',
                'password'   => Hash::make('password'),
                'company_id' => null,
                'status'     => 1,
            ]
        );

        // Assign role
        if (! $admin->hasRole('super_admin')) {
            $admin->assignRole($role);
        }

        $this->command->info('✅ Super admin created:');
        $this->command->info('   Email:    superadmin@example.com');
        $this->command->info('   Password: password');
    }
}