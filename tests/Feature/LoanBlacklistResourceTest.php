<?php

use App\Filament\Resources\LoanBlacklistResource\Pages\CreateLoanBlacklist;
use App\Filament\Resources\LoanBlacklistResource\Pages\ListLoanBlacklists;
use App\Models\LoanBlacklist;
use App\Models\Member;
use Livewire\Livewire;

beforeEach(function () {
    $this->actor = asSuperAdmin();
    $this->member = Member::factory()->create();
});

it('marks a member as blacklisted (active, recorded_by set)', function () {
    Livewire::test(CreateLoanBlacklist::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'blacklisted_at' => now()->toDateString(),
            'reason' => 'Angsuran macet berbulan-bulan.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $bl = LoanBlacklist::where('member_id', $this->member->id)->first();

    expect($bl->is_active)->toBeTrue()
        ->and($bl->recorded_by)->toBe($this->actor->id);
});

it('releases an active blacklist', function () {
    $bl = LoanBlacklist::factory()->create(['member_id' => $this->member->id, 'is_active' => true]);

    Livewire::test(ListLoanBlacklists::class)
        ->callTableAction('release', $bl);

    $bl->refresh();
    expect($bl->is_active)->toBeFalse()
        ->and($bl->released_at)->not->toBeNull();
});
