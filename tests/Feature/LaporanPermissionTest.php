<?php

use App\Filament\Pages\LaporanAngsuranPinjaman;
use App\Filament\Pages\LaporanSetoranSimpanan;

// Split permission (dari security review): view boleh Petugas+, export Pengurus-only.

it('grants report view access to both petugas and pengurus', function () {
    expect(asPetugas()->can(LaporanSetoranSimpanan::PERMISSION))->toBeTrue()
        ->and(asPetugas()->can(LaporanAngsuranPinjaman::PERMISSION))->toBeTrue()
        ->and(asPengurus()->can(LaporanSetoranSimpanan::PERMISSION))->toBeTrue()
        ->and(asPengurus()->can(LaporanAngsuranPinjaman::PERMISSION))->toBeTrue();
});

it('withholds report export from petugas but grants it to pengurus', function () {
    expect(asPetugas()->can(LaporanSetoranSimpanan::EXPORT_PERMISSION))->toBeFalse()
        ->and(asPetugas()->can(LaporanAngsuranPinjaman::EXPORT_PERMISSION))->toBeFalse()
        ->and(asPengurus()->can(LaporanSetoranSimpanan::EXPORT_PERMISSION))->toBeTrue()
        ->and(asPengurus()->can(LaporanAngsuranPinjaman::EXPORT_PERMISSION))->toBeTrue();
});

it('lets petugas and pengurus reach both report pages', function () {
    asPetugas();
    expect(LaporanSetoranSimpanan::canAccess())->toBeTrue()
        ->and(LaporanAngsuranPinjaman::canAccess())->toBeTrue();

    asPengurus();
    expect(LaporanSetoranSimpanan::canAccess())->toBeTrue()
        ->and(LaporanAngsuranPinjaman::canAccess())->toBeTrue();
});
