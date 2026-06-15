<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'admin example',
                'password' => Hash::make('password'),
            ],
        );

        $admin->assignRole($superAdmin);
    }
}
