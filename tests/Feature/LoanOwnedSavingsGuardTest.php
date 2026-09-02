<?php

use App\Actions\RecordMemberSavingsDeposits;
use App\Actions\ReverseTransaction;
use App\Enums\LoanStatus;
use App\Exceptions\CannotCancelLoan;
use App\Exceptions\CannotReverseTransaction;
use App\Exceptions\UnsupportedSavingsType;
use App\Livewire\Savings\Deposit\SavingsDepositForm;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use App\Services\DepositReportService;
use App\Services\LoanPaymentService;
use App\Services\SavingsBalanceService;
use App\Services\WithdrawalWorkflow;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Dua lubang yang ditemukan security review atas perubahan "SWP & Tabungan
 * Berjangka jadi simpanan sungguhan".
 *
 * **H1** — `savings_type` datang dari payload dan tak pernah divalidasi. Dulu
 * enum MySQL jadi jaring terakhirnya; migrasi yang melebarkan enum itu untuk
 * `swp`/`tabungan_berjangka` menghapus jaringnya. Terverifikasi sebelum
 * diperbaiki: satu panggilan mencetak simpanan SWP 9.999.999 dan saldonya naik
 * segitu — dan karena `swp` bisa dicairkan, itu jalur uang keluar sungguhan.
 *
 * **H2** — setoran SWP/Tab Berjangka muncul di daftar Setoran biasa dengan
 * tombol Reversal aktif, dan `reverse_savings::deposit` dipegang Petugas.
 * Dibalik sendirian, saldo anggota turun sementara pinjaman & angsurannya tetap
 * berdiri. Codebase sudah punya guard persis untuk kasus kembarannya
 * (`allowPairedInstallmentDebit`); sisi setoran terlewat.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->member = Member::factory()->create(['status' => 'Aktif']);
    $this->balances = app(SavingsBalanceService::class);
});

/** @return array{0: Loan, 1: Collection} */
function loanForGuard(string $memberId, int $userId): array
{
    $loan = Loan::factory()->create([
        'member_id' => $memberId,
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 2000000,
        'term_months' => 2,
        'monthly_principal' => 1000000,
        'monthly_interest' => 0,
        'monthly_time_deposit' => 12000,
        'swp_amount' => 30000,
        'recorded_by' => $userId,
    ]);

    $schedules = collect(range(1, 2))->map(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 0,
        'time_deposit_due' => 12000,
        'total_due' => 1012000,
    ]));

    return [$loan, $schedules];
}

// ── H1 ────────────────────────────────────────────────────────────────────

function mintDeposit(string $memberId, string $type, string $amount = '9999999'): void
{
    app(RecordMemberSavingsDeposits::class)([[
        'member_id' => $memberId,
        'savings_type' => $type,
        'amount' => $amount,
        'idempotency_key' => (string) Str::uuid(),
        'deposit_date' => now()->toDateString(),
        'period_month' => now()->startOfMonth()->toDateString(),
        'deposit_method' => 'setor_sendiri',
        'deposited_by' => 'bendahara',
        // Causer WAJIB pengguna sungguhan: `savings_deposits.recorded_by` punya
        // foreign key ke `users`. Id yang dipatok lolos di sqlite dan gagal di
        // MySQL — dan MySQL yang dipakai menjalankan suite ini.
    ]], test()->user->id);
}

it('refuses to mint an swp deposit through the manual deposit engine', function () {
    expect(fn () => mintDeposit($this->member->id, 'swp'))
        ->toThrow(UnsupportedSavingsType::class);

    expect(SavingsDeposit::where('member_id', $this->member->id)->count())->toBe(0)
        ->and($this->balances->balanceByType($this->member, 'swp'))->toBe('0.00');
});

it('refuses to mint a tabungan berjangka deposit the same way', function () {
    expect(fn () => mintDeposit($this->member->id, 'tabungan_berjangka'))
        ->toThrow(UnsupportedSavingsType::class);

    expect($this->balances->balanceByType($this->member, 'tabungan_berjangka'))->toBe('0.00');
});

it('refuses a savings type that does not exist at all', function () {
    expect(fn () => mintDeposit($this->member->id, 'karangan_bebas'))
        ->toThrow(UnsupportedSavingsType::class);
});

/** Jenis manual yang sah tetap lewat — guard-nya daftar putih, bukan pemblokir. */
it('still records an ordinary sukarela deposit', function () {
    mintDeposit($this->member->id, 'sukarela', '50000');

    expect($this->balances->balanceByType($this->member, 'sukarela'))->toBe('50000.00');
});

/** Di layar loket penolakannya berupa pesan, bukan exception mentah. */
it('rejects an injected savings type at the counter form with a message', function () {
    asPetugas();

    // Tanggal & periode disetel LEBIH DULU: `updatedDepositDate`/
    // `updatedPeriodMonth` membangun ulang `lines`, jadi menyetelnya setelah
    // injeksi akan menghapus injeksinya — dan test-nya hijau tanpa menguji apa
    // pun. Request Livewire sungguhan mengirim properti sekaligus, jadi
    // penyerang tak terkena urutan itu.
    $form = Livewire::test(SavingsDepositForm::class)
        ->set('deposit_date', now()->toDateString())
        ->set('period_month', now()->format('Y-m'))
        ->call('selectMember', $this->member->id);

    $lines = $form->get('lines');
    $lines[0]['include'] = true;
    $lines[0]['savings_type'] = 'swp';
    $lines[0]['amount'] = '9999999';

    $form->set('lines', $lines)
        ->call('save')
        ->assertHasErrors('lines.0.savings_type');

    expect(SavingsDeposit::where('member_id', $this->member->id)->where('savings_type', 'swp')->count())->toBe(0);
});

// ── H2 ────────────────────────────────────────────────────────────────────

it('refuses to reverse an swp deposit on its own', function () {
    [$loan] = loanForGuard($this->member->id, $this->user->id);

    $deposit = SavingsDeposit::where('member_id', $this->member->id)
        ->where('savings_type', 'swp')->sole();

    expect(fn () => app(ReverseTransaction::class)($deposit, 'coba hapus hak anggota', $this->user->id))
        ->toThrow(CannotReverseTransaction::class, 'mengikuti pinjaman');

    expect($this->balances->balanceByType($this->member, 'swp'))->toBe('30000.00')
        ->and($loan->fresh()->status->value)->toBe('Cair');
});

it('refuses to reverse a tabungan berjangka deposit on its own', function () {
    [, $schedules] = loanForGuard($this->member->id, $this->user->id);

    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1012000], $this->user->id);

    $deposit = SavingsDeposit::where('member_id', $this->member->id)
        ->where('savings_type', 'tabungan_berjangka')->sole();

    expect(fn () => app(ReverseTransaction::class)($deposit, 'coba hapus hak anggota', $this->user->id))
        ->toThrow(CannotReverseTransaction::class);

    expect($this->balances->balanceByType($this->member, 'tabungan_berjangka'))->toBe('12000.00');
});

/** Tombolnya pun tak boleh muncul — bukan muncul lalu gagal. */
it('hides the reversal action from petugas for loan-owned deposits', function () {
    $petugas = asPetugas();

    loanForGuard($this->member->id, $this->user->id);

    $swp = SavingsDeposit::where('savings_type', 'swp')->sole();
    $sukarela = SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $this->member->id, 'amount' => 50000,
    ]);

    expect($petugas->can('reverse', $swp))->toBeFalse()
        // Setoran biasa tetap bisa dibalik Petugas — guard ini sempit, bukan
        // pencabutan wewenang.
        ->and($petugas->can('reverse', $sukarela))->toBeTrue();
});

/** Jalur yang SAH tetap terbuka: pembatalan pinjaman & reversal angsuran. */
it('still reverses them through the loan and installment paths', function () {
    [$loan, $schedules] = loanForGuard($this->member->id, $this->user->id);

    $installment = app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1012000], $this->user->id);

    app(LoanPaymentService::class)->reverse($installment, 'salah input', $this->user->id);

    expect($this->balances->balanceByType($this->member, 'tabungan_berjangka'))->toBe('0.00');

    $loan->fresh()->update(['status' => LoanStatus::Dibatalkan]);

    expect($this->balances->balanceByType($this->member, 'swp'))->toBe('0.00');
});

// ── Laporan setoran ───────────────────────────────────────────────────────

/**
 * SWP & Tabungan Berjangka dikecualikan dari Laporan Setoran Simpanan.
 *
 * Keduanya bukan setoran yang diterima loket: SWP dipotong dari dana
 * pencairan, Tabungan Berjangka komponen angsuran yang sudah terhitung di
 * Laporan Angsuran. Yang paling merusak: barisnya ditandai
 * `deposit_method = 'potong_gaji'` mengikuti angsurannya, jadi ia ikut basis
 * rekonsiliasi payroll — total per OPD naik tanpa potongan gaji yang nyata.
 */
it('keeps loan-owned savings out of the deposit report', function () {
    [, $schedules] = loanForGuard($this->member->id, $this->user->id);

    app(LoanPaymentService::class)->pay(
        $schedules[0],
        ['amount_paid' => 1012000, 'payment_method' => 'potong_gaji'],
        $this->user->id,
    );

    mintDeposit($this->member->id, 'sukarela', '50000');

    $filters = [
        'start' => now()->startOfYear()->toDateString(),
        'end' => now()->endOfYear()->toDateString(),
        'basis' => 'deposit_date',
    ];

    $rows = app(DepositReportService::class)->rows($filters);

    expect($rows->pluck('savings_type')->unique()->all())->toBe(['sukarela'])
        ->and($rows->sum(fn ($r) => (float) $r->amount))->toBe(50000.0);
});

/** Basis rekonsiliasi payroll — tempat kerusakannya paling terasa. */
it('keeps them out of the payroll reconciliation basis too', function () {
    [, $schedules] = loanForGuard($this->member->id, $this->user->id);

    app(LoanPaymentService::class)->pay(
        $schedules[0],
        ['amount_paid' => 1012000, 'payment_method' => 'potong_gaji'],
        $this->user->id,
    );

    $total = app(DepositReportService::class)->totals([
        'start' => now()->startOfYear()->toDateString(),
        'end' => now()->endOfYear()->toDateString(),
        'basis' => 'period_month',
    ]);

    expect($total)->toBe('0.00');
});

// ── Saldo tak boleh negatif ───────────────────────────────────────────────

/**
 * Terverifikasi sebelum diperbaiki: pinjaman cair (SWP 500.000) → anggota
 * menarik 500.000 → petugas membatalkan pinjaman karena salah input → saldo SWP
 * jadi **−500.000**, dan total simpanan anggota ikut minus.
 */
it('refuses to cancel a loan whose swp the member already withdrew', function () {
    $pengurus = asPengurus();

    $loan = Loan::factory()->create([
        'member_id' => $this->member->id,
        'swp_amount' => 500000,
        'recorded_by' => $this->user->id,
    ]);

    $withdrawal = SavingsWithdrawal::factory()->type('swp')->status('acc')->create([
        'member_id' => $this->member->id, 'amount' => 500000, 'is_reversal' => false,
    ]);

    app(WithdrawalWorkflow::class)->disburse($withdrawal, $pengurus->id);

    expect($this->balances->balanceByType($this->member, 'swp'))->toBe('0.00');

    expect(fn () => $loan->fresh()->update(['status' => LoanStatus::Dibatalkan]))
        ->toThrow(CannotCancelLoan::class, 'sudah ditarik anggota');

    // Saldo tetap 0, tidak minus — dan pinjamannya tetap berdiri.
    expect($this->balances->balanceByType($this->member->fresh(), 'swp'))->toBe('0.00')
        ->and((float) $this->balances->totalBalance($this->member->fresh()))->toBeGreaterThanOrEqual(0.0);
});

/** Urutan yang benar tetap terbuka: batalkan pencairannya dulu. */
it('allows the cancellation once the swp withdrawal is reversed', function () {
    $pengurus = asPengurus();

    $loan = Loan::factory()->create([
        'member_id' => $this->member->id,
        'swp_amount' => 500000,
        'recorded_by' => $this->user->id,
    ]);

    $withdrawal = SavingsWithdrawal::factory()->type('swp')->status('acc')->create([
        'member_id' => $this->member->id, 'amount' => 500000, 'is_reversal' => false,
    ]);

    app(WithdrawalWorkflow::class)->disburse($withdrawal, $pengurus->id);

    app(ReverseTransaction::class)($withdrawal->fresh(), 'salah cairkan', $pengurus->id, allowInactiveMember: true);

    $loan->fresh()->update(['status' => LoanStatus::Dibatalkan]);

    expect($this->balances->balanceByType($this->member->fresh(), 'swp'))->toBe('0.00');
});

/** Pembatalan biasa (SWP masih utuh) tidak terkekang sama sekali. */
it('still cancels a loan whose swp is untouched', function () {
    $loan = Loan::factory()->create([
        'member_id' => $this->member->id,
        'swp_amount' => 500000,
        'recorded_by' => $this->user->id,
    ]);

    expect($this->balances->balanceByType($this->member, 'swp'))->toBe('500000.00');

    $loan->update(['status' => LoanStatus::Dibatalkan]);

    expect($this->balances->balanceByType($this->member->fresh(), 'swp'))->toBe('0.00');
});
