<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kredensial Admin Awal
    |--------------------------------------------------------------------------
    |
    | Dipakai UserSeeder untuk membuat akun super_admin pertama. Di environment
    | produksi kedua nilai ini WAJIB diisi — UserSeeder menolak jalan tanpa
    | keduanya, supaya tidak ada instalasi yang berakhir dengan kredensial
    | default yang bisa ditebak.
    |
    | Di local/testing boleh dikosongkan; seeder jatuh ke default lama
    | (admin@example.com / password) agar alur pengembangan tidak berubah.
    |
    */

    'admin_email' => env('SEED_ADMIN_EMAIL'),

    'admin_password' => env('SEED_ADMIN_PASSWORD'),

];
