<?php

use App\Actions\ReverseTransaction;
use App\Enums\WithdrawalStatus;
use App\Exceptions\CannotProcessWithdrawal;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Services\WithdrawalWorkflow;
use App\Settings\CooperativeSettings;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

/**
 * Tabungan Berjangka dikembalikan **satu kali dalam setahun** (keputusan
 * pengurus, bersamaan pembagian SHU).
 *
 * Yang dijaga: pencairan kedua di dalam 12 bulan ditolak; bypass-nya jatuh ke
 * permission tersendiri yang BUKAN milik Pengurus — `disburse` sendiri sudah
 * Pengurus-only, jadi membebaskan Pengurus sama dengan tak punya aturan; dan
 * setiap bypass meninggalkan jejaknya sendiri.
 */
beforeEach(function () {
    $this->workflow = app(WithdrawalWorkflow::class);
    $this->member = Member::factory()->create(['status' => 'Aktif']);

    SavingsDeposit::factory()->type('tabungan_berjangka')->create([
        'member_id' => $this->member->id,
        'amount' => 500000,
    ]);
});

/** Pencairan Tab Berjangka berstatus acc, siap dicairkan. */
function timeDepositWithdrawal(string $memberId, int $amount = 100000): SavingsWithdrawal
{
    return SavingsWithdrawal::factory()->type('tabungan_berjangka')->status('acc')->create([
        'member_id' => $memberId,
        'amount' => $amount,
        'is_reversal' => false,
    ]);
}

/** Pencairan Tab Berjangka yang SUDAH cair pada tanggal tertentu. */
function disbursedTimeDeposit(string $memberId, string $when, int $amount = 100000): SavingsWithdrawal
{
    return SavingsWithdrawal::factory()->type('tabungan_berjangka')->cair()->create([
        'member_id' => $memberId,
        'amount' => $amount,
        'is_reversal' => false,
        'withdrawal_date' => $when,
        'disbursed_at' => $when,
    ]);
}

it('allows the first ever tabungan berjangka disbursement', function () {
    $pengurus = asPengurus();

    $withdrawal = timeDepositWithdrawal($this->member->id);

    $this->workflow->disburse($withdrawal, $pengurus->id);

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Cair);
});

it('refuses a second disbursement within twelve months', function () {
    $pengurus = asPengurus();

    disbursedTimeDeposit($this->member->id, now()->subMonths(5)->toDateString());

    $withdrawal = timeDepositWithdrawal($this->member->id);

    expect(fn () => $this->workflow->disburse($withdrawal, $pengurus->id))
        ->toThrow(CannotProcessWithdrawal::class, 'satu kali dalam setahun');

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Acc);
});

it('allows it again once twelve months have passed', function () {
    $pengurus = asPengurus();

    disbursedTimeDeposit($this->member->id, now()->subYear()->subDay()->toDateString());

    $withdrawal = timeDepositWithdrawal($this->member->id);

    $this->workflow->disburse($withdrawal, $pengurus->id);

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Cair);
});

/**
 * Inti rancangannya: Pengurus TIDAK otomatis lolos. Kalau ia lolos, aturannya
 * tak pernah menolak siapa pun — `disburse` memang cuma dipegang Pengurus.
 */
it('does not let pengurus bypass the schedule by role alone', function () {
    $pengurus = asPengurus();

    expect($pengurus->can(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION))->toBeFalse();

    disbursedTimeDeposit($this->member->id, now()->subMonth()->toDateString());

    expect(fn () => $this->workflow->disburse(timeDepositWithdrawal($this->member->id), $pengurus->id))
        ->toThrow(CannotProcessWithdrawal::class);
});

it('lets a holder of the bypass permission through, and records it', function () {
    $pengurus = asPengurus();
    $pengurus->givePermissionTo(Permission::findOrCreate(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION, 'web'));

    disbursedTimeDeposit($this->member->id, now()->subMonths(3)->toDateString());

    $withdrawal = timeDepositWithdrawal($this->member->id);

    $this->workflow->disburse($withdrawal, $pengurus->id);

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Cair);

    // Bypass tanpa jejak = pencairan di luar jadwal yang tak pernah ditanyakan.
    $log = Activity::where('event', 'pencairan_di_luar_jadwal')->latest('id')->firstOrFail();

    expect($log->properties['attributes']['savings_type'])->toBe('tabungan_berjangka')
        ->and($log->properties['attributes']['next_eligible_at'])->not->toBeEmpty();
});

/** super_admin memegangnya lewat seeder — bukan hardcode di kode. */
it('gives the bypass permission to super_admin only', function () {
    $superAdmin = asRole('super_admin');
    $pengurus = asRole('pengurus');
    $petugas = asRole('petugas');

    expect($superAdmin->can(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION))->toBeTrue()
        ->and($pengurus->can(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION))->toBeFalse()
        ->and($petugas->can(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION))->toBeFalse();
});

/** Jadwal ini HANYA berlaku untuk Tabungan Berjangka — jenis lain tak terkekang. */
it('leaves the other savings types unrestricted', function () {
    $pengurus = asPengurus();

    SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $this->member->id, 'amount' => 500000,
    ]);

    SavingsWithdrawal::factory()->type('sukarela')->cair()->create([
        'member_id' => $this->member->id, 'amount' => 100000, 'is_reversal' => false,
        'withdrawal_date' => now()->subMonth()->toDateString(), 'disbursed_at' => now()->subMonth(),
    ]);

    $second = SavingsWithdrawal::factory()->type('sukarela')->status('acc')->create([
        'member_id' => $this->member->id, 'amount' => 100000, 'is_reversal' => false,
    ]);

    $this->workflow->disburse($second, $pengurus->id);

    expect($second->fresh()->status)->toBe(WithdrawalStatus::Cair);
});

/**
 * Pencairan yang sudah dibatalkan tak boleh mengunci anggota selamanya —
 * uangnya kembali, jadi jadwalnya juga harus kembali terbuka.
 *
 * Dibalik lewat `ReverseTransaction` yang asli, BUKAN dengan membuat baris
 * ber-`is_reversal = true` sendirian. Versi pertama test ini melakukan yang
 * kedua dan karena itu lulus tanpa membuktikan apa pun: pembalikan sungguhan
 * MEMBIARKAN baris asli utuh (`is_reversal = false`, status tetap `Cair`), dan
 * baris asli itulah yang menghalangi. Guard-nya benar-benar bocor sampai
 * `whereDoesntHave('reversal')` ditambahkan.
 */
it('reopens the schedule once the disbursement is reversed', function () {
    $pengurus = asPengurus();

    $first = disbursedTimeDeposit($this->member->id, now()->subMonth()->toDateString());

    app(ReverseTransaction::class)($first, 'salah cairkan', $pengurus->id, allowInactiveMember: true);

    $withdrawal = timeDepositWithdrawal($this->member->id);

    $this->workflow->disburse($withdrawal, $pengurus->id);

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Cair);
});

/** Tapi pencairan yang MASIH berdiri tetap menghalangi. */
it('still blocks while the earlier disbursement stands unreversed', function () {
    $pengurus = asPengurus();

    disbursedTimeDeposit($this->member->id, now()->subMonth()->toDateString());

    expect(fn () => $this->workflow->disburse(timeDepositWithdrawal($this->member->id), $pengurus->id))
        ->toThrow(CannotProcessWithdrawal::class);
});

// ── Jendela SHU ───────────────────────────────────────────────────────────

/**
 * Begitu bulan pembagian SHU ditetapkan, patokannya pindah: bukan lagi "12
 * bulan sejak pencairan terakhir" (yang melayang per anggota — satu orang cair
 * Januari, yang lain Juli, keduanya sah "sekali setahun" tapi tak satu pun
 * bersamaan SHU), melainkan jendela yang sama untuk semua orang.
 */
function setShuMonth(?int $month): void
{
    $settings = app(CooperativeSettings::class);
    $settings->shu_distribution_month = $month;
    $settings->save();
}

it('allows a disbursement inside the SHU window', function () {
    $pengurus = asPengurus();

    setShuMonth((int) now()->format('n'));

    $withdrawal = timeDepositWithdrawal($this->member->id);

    $this->workflow->disburse($withdrawal, $pengurus->id);

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Cair);
});

it('refuses a disbursement outside the SHU window', function () {
    $pengurus = asPengurus();

    // Bulan SHU disetel ke bulan yang bukan bulan ini.
    setShuMonth((int) now()->addMonths(3)->format('n'));

    expect(fn () => $this->workflow->disburse(timeDepositWithdrawal($this->member->id), $pengurus->id))
        ->toThrow(CannotProcessWithdrawal::class, 'bulan pembagian SHU');
});

/** Jendela sebulan tak boleh dipakai dua kali — karena itu aturannya dua bagian. */
it('refuses a second disbursement within the same SHU window', function () {
    $pengurus = asPengurus();

    setShuMonth((int) now()->format('n'));

    disbursedTimeDeposit($this->member->id, now()->subDays(3)->toDateString());

    expect(fn () => $this->workflow->disburse(timeDepositWithdrawal($this->member->id), $pengurus->id))
        ->toThrow(CannotProcessWithdrawal::class, 'sudah dicairkan tahun ini');
});

/**
 * Pembanding yang menunjukkan bedanya: pencairan 13 bulan lalu LOLOS aturan
 * lama (sudah >12 bulan) tapi tetap ditolak jendela SHU bila sekarang bukan
 * bulannya. Itu justru maksudnya — "sekali setahun" jadi "bersamaan SHU".
 */
it('still refuses outside the window even when a year has passed', function () {
    $pengurus = asPengurus();

    setShuMonth((int) now()->addMonths(2)->format('n'));

    disbursedTimeDeposit($this->member->id, now()->subMonths(13)->toDateString());

    expect(fn () => $this->workflow->disburse(timeDepositWithdrawal($this->member->id), $pengurus->id))
        ->toThrow(CannotProcessWithdrawal::class, 'bulan pembagian SHU');
});

it('lets the bypass permission holder through the SHU window too, and records why', function () {
    $pengurus = asPengurus();
    $pengurus->givePermissionTo(Permission::findOrCreate(WithdrawalWorkflow::BYPASS_SCHEDULE_PERMISSION, 'web'));

    setShuMonth((int) now()->addMonths(4)->format('n'));

    $withdrawal = timeDepositWithdrawal($this->member->id);

    $this->workflow->disburse($withdrawal, $pengurus->id);

    $log = Activity::where('event', 'pencairan_di_luar_jadwal')->latest('id')->firstOrFail();

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Cair)
        ->and($log->properties['attributes']['alasan'])->toBe('di luar bulan pembagian SHU');
});

// ── Pengecualian status anggota ───────────────────────────────────────────

/**
 * Anggota Keluar / Meninggal butuh haknya sekarang, bukan menunggu bulan SHU.
 * Memaksa kasus sah ini menempuh izin bypass akan membuat izin itu jadi
 * kebutuhan harian — dan izin yang sering dipakai berhenti jadi kontrol.
 */
it('exempts a member who has left the cooperative', function () {
    $pengurus = asPengurus();

    setShuMonth((int) now()->addMonths(5)->format('n'));
    $this->member->update(['status' => 'Keluar']);

    $withdrawal = timeDepositWithdrawal($this->member->id);

    $this->workflow->disburse($withdrawal, $pengurus->id);

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Cair)
        // Bukan bypass — tak ada jejak "di luar jadwal", karena memang tak melanggar.
        ->and(Activity::where('event', 'pencairan_di_luar_jadwal')->exists())->toBeFalse();
});

it('exempts a deceased member from the rolling rule as well', function () {
    $pengurus = asPengurus();

    setShuMonth(null);
    disbursedTimeDeposit($this->member->id, now()->subMonth()->toDateString());
    $this->member->update(['status' => 'Meninggal']);

    $withdrawal = timeDepositWithdrawal($this->member->id);

    $this->workflow->disburse($withdrawal, $pengurus->id);

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Cair);
});

/** Anggota Aktif tetap terikat — pengecualiannya sempit. */
it('keeps active members bound by the window', function () {
    $pengurus = asPengurus();

    setShuMonth((int) now()->addMonths(5)->format('n'));

    expect($this->member->status)->toBe('Aktif');

    expect(fn () => $this->workflow->disburse(timeDepositWithdrawal($this->member->id), $pengurus->id))
        ->toThrow(CannotProcessWithdrawal::class);
});
