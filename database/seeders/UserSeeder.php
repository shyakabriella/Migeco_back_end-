<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Important:
     * Only Admin is created here.
     * All other users must be created later by Admin inside the system.
     */
    public function run(): void
    {
        $adminRole = Role::where('slug', 'admin')->first();

        if (!$adminRole) {
            $this->command->error('Admin role not found. Please run RoleSeeder first.');
            return;
        }

        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@migeco.com')],
            [
                'role_id' => $adminRole->id,
                'name' => env('ADMIN_NAME', 'System Admin'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'Admin@12345')),
                'phone' => env('ADMIN_PHONE', null),
                'department' => 'System Administration',
                'status' => 'active',
                'created_by' => null,
            ]
        );
    }
}