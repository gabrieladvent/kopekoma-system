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

        $email = config('seeding.admin_email');
        $password = config('seeding.admin_password');

        // Di produksi kredensial WAJIB datang dari environment. Tanpa itu seeder
        // dulu membuat admin@example.com / "password" sebagai super_admin —
        // super_admin melewati semua policy lewat Gate::before, jadi itu akses
        // penuh ke PII anggota dan seluruh mutasi finansial dengan satu tebakan.
        if (app()->environment('production') && ($email === null || $password === null)) {
            throw new \RuntimeException(
                'UserSeeder ditolak di produksi: set SEED_ADMIN_EMAIL dan SEED_ADMIN_PASSWORD '
                .'di .env sebelum menjalankan db:seed.'
            );
        }

        // firstOrCreate, BUKAN updateOrCreate: updateOrCreate menulis ulang
        // password setiap kali seeder jalan, diam-diam mengembalikan kredensial
        // default sesudah admin merotasinya.
        $admin = User::firstOrCreate(
            ['email' => $email ?? 'admin@example.com'],
            [
                'name' => 'admin example',
                'password' => Hash::make($password ?? 'password'),
            ],
        );

        $admin->assignRole($superAdmin);
    }
}
