<?php

use App\Settings\CooperativeSettings;
use App\Support\ReportLetterhead;

beforeEach(fn () => asSuperAdmin());

/**
 * @param  array<string, string|null>  $overrides
 */
function setCoopIdentity(array $overrides = []): void
{
    $coop = app(CooperativeSettings::class);
    foreach ($overrides as $key => $value) {
        $coop->{$key} = $value;
    }
    $coop->save();
}

it('formats a local phone number with a +62 prefix for the letterhead', function () {
    setCoopIdentity(['cooperative_phone' => '0361 234567']);

    expect(ReportLetterhead::make()['phone'])->toBe('+62 361 234567');
});

it('strips a leading 62 before re-prefixing', function () {
    setCoopIdentity(['cooperative_phone' => '62812345678']);

    expect(ReportLetterhead::make()['phone'])->toBe('+62 812345678');
});

it('keeps an explicit + international number as-is', function () {
    setCoopIdentity(['cooperative_phone' => '+1 202 555 0100']);

    expect(ReportLetterhead::make()['phone'])->toBe('+1 202 555 0100');
});

it('returns null phone when unset', function () {
    setCoopIdentity(['cooperative_phone' => null]);

    expect(ReportLetterhead::make()['phone'])->toBeNull();
});

it('exposes the cooperative identity fields for the kop', function () {
    setCoopIdentity([
        'cooperative_address' => 'Jl. Merdeka 1',
        'cooperative_city' => 'Denpasar',
        'signatory_name' => 'I Made Sudana',
        'signatory_position' => 'Ketua',
    ]);

    $kop = ReportLetterhead::make();

    expect($kop['address'])->toBe('Jl. Merdeka 1')
        ->and($kop['city'])->toBe('Denpasar')
        ->and($kop['signatory_name'])->toBe('I Made Sudana')
        ->and($kop['signatory_position'])->toBe('Ketua');
});
