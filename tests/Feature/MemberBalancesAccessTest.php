<?php

use App\Livewire\Savings\MemberBalances;
use App\Models\Member;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Saldo Anggota & modul Sistem — izin yang hilang dan peran yang dipatok.
 *
 * `MemberBalances::mount()` sudah memeriksa `view_any_member::savings::balance`
 * sejak awal, tapi izinnya tak pernah dibuat siapa pun. Permission yang tak ada
 * selalu menjawab "tidak", jadi halamannya 403 untuk SEMUA ORANG — super_admin
 * sekalipun, yang mendapat izinnya lewat `Permission::all()` dan tak akan
 * menemukan baris yang tak pernah ada.
 */
it('creates the balance permission the page has always demanded', function () {
    asPengurus(); // menjalankan RolePermissionSeeder

    expect(Permission::query()->where('name', 'view_any_member::savings::balance')->exists())->toBeTrue();
});

it('opens saldo anggota for pengurus, and keeps petugas out', function () {
    asPengurus();
    $this->get(route('savings.balances'))->assertOk();

    // Batasannya memang begitu sejak awal; yang diperbaiki adalah bahwa dulu
    // Pengurus pun ikut tertutup.
    asPetugas();
    $this->get(route('savings.balances'))->assertForbidden();
});

it('opens saldo anggota for super_admin too', function () {
    asSuperAdmin();

    Member::factory()->create();

    Livewire::test(MemberBalances::class)->assertOk();
});

/**
 * Modul Sistem kini bergantung IZIN, bukan peran. Bawaannya tetap super_admin
 * saja — yang berubah cuma: sekarang bisa diberikan, dan terlihat di layar Peran.
 */
it('keeps the system module closed to pengurus and petugas', function () {
    asPengurus();
    $this->get(route('system.users'))->assertForbidden();
    $this->get(route('system.roles'))->assertForbidden();

    asPetugas();
    $this->get(route('system.users'))->assertForbidden();
});

it('opens the system module to a permission holder who is not super_admin', function () {
    $pengurus = asPengurus();

    $pengurus->givePermissionTo('access_system_users');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($pengurus->fresh());

    $this->get(route('system.users'))->assertOk();

    // Diberikan satu per satu — izin Peran tidak ikut terbawa.
    $this->get(route('system.roles'))->assertForbidden();
});
