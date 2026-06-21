<?php

use App\Models\Grade;

it('reads a decimal money column as a clean integer, not a "X.00" string', function () {
    // Akar bug ×100: decimal:2 mengembalikan "150000.00"; bila titiknya di-strip
    // MoneyInput nilainya jadi "15000000". Cast WholeRupiah menutup ini di sumber.
    $grade = Grade::create(['code' => 'GOL-T1', 'name' => 'Test', 'mandatory_savings_amount' => 150000, 'is_active' => true]);

    $value = $grade->fresh()->mandatory_savings_amount;

    expect($value)->toBe(150000)
        ->and((string) $value)->toBe('150000')
        ->and((string) $value)->not->toContain('.');
});

it('normalizes a decimal-string input on write to a whole integer', function () {
    $grade = Grade::create(['code' => 'GOL-T2', 'name' => 'Test', 'mandatory_savings_amount' => '150000.00', 'is_active' => true]);

    expect($grade->fresh()->mandatory_savings_amount)->toBe(150000);
});

it('keeps null money as null', function () {
    $grade = new Grade(['mandatory_savings_amount' => null]);

    expect($grade->mandatory_savings_amount)->toBeNull();
});
