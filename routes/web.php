<?php

use App\Actions\ExportSalaryDeductionRecap;
use App\Filament\Resources\InstallmentResource;
use App\Filament\Resources\LoanResource;
use App\Filament\Resources\SavingsDepositResource;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Loan\Blacklist\LoanBlacklistDetail;
use App\Livewire\Loan\Blacklist\LoanBlacklists;
use App\Livewire\Loan\Installment\BatchInstallmentPayment;
use App\Livewire\Loan\Installment\InstallmentDetail;
use App\Livewire\Loan\Installment\InstallmentForm;
use App\Livewire\Loan\Installment\Installments;
use App\Livewire\Loan\LoanDetail;
use App\Livewire\Loan\LoanForm;
use App\Livewire\Loan\Loans;
use App\Livewire\Master\Agency\Agencies;
use App\Livewire\Master\Agency\AgencyDetail;
use App\Livewire\Master\Grade\GradeDetail;
use App\Livewire\Master\Grade\Grades;
use App\Livewire\Master\Member\MemberDetail;
use App\Livewire\Master\Member\MemberForm;
use App\Livewire\Master\Member\Members;
use App\Livewire\Profile\EditProfile;
use App\Livewire\Savings\Deposit\BatchSalaryDeduction;
use App\Livewire\Savings\Deposit\SavingsDepositDetail;
use App\Livewire\Savings\Deposit\SavingsDepositForm;
use App\Livewire\Savings\Deposit\SavingsDeposits;
use App\Livewire\Savings\Holiday\HolidayRegistrationDetail;
use App\Livewire\Savings\Holiday\HolidayRegistrationForm;
use App\Livewire\Savings\Holiday\HolidayRegistrations;
use App\Livewire\Savings\MemberBalances;
use App\Livewire\Savings\MemberSavingsDetail;
use App\Livewire\Savings\Shopping\ShoppingTransactionDetail;
use App\Livewire\Savings\Shopping\ShoppingTransactionForm;
use App\Livewire\Savings\Shopping\ShoppingTransactions;
use App\Livewire\Savings\Withdrawal\SavingsWithdrawalDetail;
use App\Livewire\Savings\Withdrawal\SavingsWithdrawalForm;
use App\Livewire\Savings\Withdrawal\SavingsWithdrawals;
use App\Livewire\Settings\ManageSettings;
use App\Livewire\System\ActivityLogs;
use App\Livewire\System\RoleForm;
use App\Livewire\System\Roles;
use App\Livewire\System\UserForm;
use App\Livewire\System\Users;
use App\Models\Agency;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::view('/styleguide', 'styleguide')->name('styleguide');

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    // Profil pengguna — setiap akun mengelola profilnya sendiri (tanpa gate
    // permission). Foto, email (verifikasi ulang saat berubah), & password.
    Route::get('/profil', EditProfile::class)->name('profile.edit');

    // Verifikasi email. Verifikasi TIDAK dipaksakan sebagai gate akses; rute ini
    // hanya melayani link konfirmasi & "kirim ulang". Link signed dari notifikasi.
    Route::get('/email/verify', fn () => redirect()->route('profile.edit'))
        ->name('verification.notice');

    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route('profile.edit')
            ->with('toast', ['type' => 'success', 'message' => 'Email berhasil diverifikasi.']);
    })->middleware('signed')->name('verification.verify');

    // Setor Simpanan (menu utama — di luar group Simpanan). Mode "Setoran Tunggal":
    // sekali proses → banyak setoran per jenis. Immutable; koreksi via reversal.
    // Rute statis (create) didahulukan sebelum {deposit} agar tak tertangkap UUID.
    Route::get('/setor-simpanan', SavingsDeposits::class)
        ->middleware('can:view_any_savings::deposit')
        ->name('savings.deposits');

    Route::get('/setor-simpanan/create', SavingsDepositForm::class)
        ->middleware('can:create_savings::deposit')
        ->name('savings.deposits.create');

    // Mode kolektif "Input per OPD" (Dokumentasi §4.4) — gating via permission khusus.
    Route::get('/setor-simpanan/batch', BatchSalaryDeduction::class)
        ->middleware('can:access_batch_salary_deduction')
        ->name('savings.deposits.batch');

    // Export rekap potong gaji (CSV). GET route agar download andal di browser.
    Route::get('/setor-simpanan/batch/export', function () {
        $data = request()->validate([
            'agency_id' => ['required', 'exists:agencies,id'],
            'period_month' => ['required', 'date_format:Y-m'],
        ]);

        return app(ExportSalaryDeductionRecap::class)(
            Agency::findOrFail($data['agency_id']),
            $data['period_month'],
        );
    })->middleware('can:export_savings_recap')->name('savings.deposits.batch.export');

    Route::get('/setor-simpanan/{deposit}/slip', function (SavingsDeposit $deposit) {
        return SavingsDepositResource::printSlip($deposit);
    })->middleware('can:view_savings::deposit')->name('savings.deposits.slip');

    Route::get('/setor-simpanan/{deposit}', SavingsDepositDetail::class)
        ->middleware('can:view_savings::deposit')
        ->name('savings.deposits.show');

    Route::post('/logout', function () {
        Auth::guard('web')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    Route::get('/master/golongan', Grades::class)
        ->middleware('can:view_any_grade')
        ->name('master.grades');

    Route::get('/master/golongan/{grade}', GradeDetail::class)
        ->middleware('can:view_grade')
        ->name('master.grades.show');

    Route::get('/master/opd', Agencies::class)
        ->middleware('can:view_any_agency')
        ->name('master.agencies');

    Route::get('/master/opd/{agency}', AgencyDetail::class)
        ->middleware('can:view_agency')
        ->name('master.agencies.show');

    // Anggota (members). Rute statis (create) didahulukan sebelum {member}
    // agar tidak tertangkap sebagai UUID anggota.
    Route::get('/master/anggota', Members::class)
        ->middleware('can:view_any_member')
        ->name('master.members');

    Route::get('/master/anggota/create', MemberForm::class)
        ->middleware('can:create_member')
        ->name('master.members.create');

    Route::get('/master/anggota/{member}/edit', MemberForm::class)
        ->middleware('can:update_member')
        ->name('master.members.edit');

    Route::get('/master/anggota/{member}/kartu', function (Member $member) {
        $pdf = Pdf::loadView('pdf.member-card', ['member' => $member->loadMissing(['agency', 'grade'])]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'kartu-anggota-'.$member->member_number.'.pdf',
        );
    })->middleware('can:view_member')->name('master.members.card');

    Route::get('/master/anggota/{member}', MemberDetail::class)
        ->middleware('can:view_member')
        ->name('master.members.show');

    // Simpanan — Pendaftaran Hari Raya. Rute statis (create) didahulukan sebelum {holiday}.
    Route::get('/simpanan/hari-raya', HolidayRegistrations::class)
        ->middleware('can:view_any_member::holiday::saving')
        ->name('savings.holiday');

    Route::get('/simpanan/hari-raya/create', HolidayRegistrationForm::class)
        ->middleware('can:create_member::holiday::saving')
        ->name('savings.holiday.create');

    Route::get('/simpanan/hari-raya/{holiday}/edit', HolidayRegistrationForm::class)
        ->middleware('can:update_member::holiday::saving')
        ->name('savings.holiday.edit');

    Route::get('/simpanan/hari-raya/{holiday}', HolidayRegistrationDetail::class)
        ->middleware('can:view_member::holiday::saving')
        ->name('savings.holiday.show');

    // Simpanan — Belanja Toko (immutable; koreksi via reversal).
    Route::get('/simpanan/belanja', ShoppingTransactions::class)
        ->middleware('can:view_any_shopping::transaction')
        ->name('savings.shopping');

    Route::get('/simpanan/belanja/create', ShoppingTransactionForm::class)
        ->middleware('can:create_shopping::transaction')
        ->name('savings.shopping.create');

    Route::get('/simpanan/belanja/{transaction}', ShoppingTransactionDetail::class)
        ->middleware('can:view_shopping::transaction')
        ->name('savings.shopping.show');

    // Simpanan — Pencairan. State machine draft → acc → cair/ditolak; immutable,
    // koreksi via reversal. Rute statis (create) didahulukan sebelum {withdrawal}.
    Route::get('/simpanan/pencairan', SavingsWithdrawals::class)
        ->middleware('can:view_any_savings::withdrawal')
        ->name('savings.withdrawals');

    Route::get('/simpanan/pencairan/create', SavingsWithdrawalForm::class)
        ->middleware('can:create_savings::withdrawal')
        ->name('savings.withdrawals.create');

    Route::get('/simpanan/pencairan/{withdrawal}', SavingsWithdrawalDetail::class)
        ->middleware('can:view_savings::withdrawal')
        ->name('savings.withdrawals.show');

    // Simpanan — Saldo Anggota (rekap read-only; gating di mount()).
    Route::get('/simpanan/saldo-anggota', MemberBalances::class)
        ->name('savings.balances');

    Route::get('/simpanan/saldo-anggota/{member}', MemberSavingsDetail::class)
        ->name('savings.balances.show');

    // Pinjaman — pencatatan akad (immutable; koreksi salah-input via reversal record).
    // Rute statis (create) & sub-modul didahulukan sebelum {loan} agar tak tertangkap UUID.
    Route::get('/pinjaman', Loans::class)
        ->middleware('can:view_any_loan')
        ->name('loans.index');

    Route::get('/pinjaman/create', LoanForm::class)
        ->middleware('can:create_loan')
        ->name('loans.create');

    // Pinjaman — Blacklist (didahulukan sebelum {loan}).
    Route::get('/pinjaman/blacklist', LoanBlacklists::class)
        ->middleware('can:view_any_loan::blacklist')
        ->name('loans.blacklist');

    Route::get('/pinjaman/blacklist/{blacklist}', LoanBlacklistDetail::class)
        ->middleware('can:view_loan::blacklist')
        ->name('loans.blacklist.show');

    Route::get('/pinjaman/{loan}/tanda-terima', function (Loan $loan) {
        return LoanResource::printReceipt($loan);
    })->middleware('can:view_loan')->name('loans.receipt');

    Route::get('/pinjaman/{loan}', LoanDetail::class)
        ->middleware('can:view_loan')
        ->name('loans.show');

    // Angsuran — pembayaran (immutable; koreksi via reversal). Pelunasan memicu refund SWP/Tab.
    Route::get('/angsuran', Installments::class)
        ->middleware('can:view_any_installment')
        ->name('installments.index');

    Route::get('/angsuran/create', InstallmentForm::class)
        ->middleware('can:create_installment')
        ->name('installments.create');

    Route::get('/angsuran/batch', BatchInstallmentPayment::class)
        ->middleware('can:access_batch_salary_deduction')
        ->name('installments.batch');

    Route::get('/angsuran/{installment}/kuitansi', function (Installment $installment) {
        return InstallmentResource::printReceipt($installment);
    })->middleware('can:view_installment')->name('installments.receipt');

    Route::get('/angsuran/{installment}', InstallmentDetail::class)
        ->middleware('can:view_installment')
        ->name('installments.show');

    Route::get('/settings', ManageSettings::class)
        ->middleware('can:manage_settings')
        ->name('settings');

    // Sistem — gating ditegakkan di mount() tiap komponen.
    Route::get('/sistem/log-aktivitas', ActivityLogs::class)->name('system.activity-logs');

    Route::get('/sistem/peran', Roles::class)->name('system.roles');
    Route::get('/sistem/peran/create', RoleForm::class)->name('system.roles.create');
    Route::get('/sistem/peran/{role}/edit', RoleForm::class)->name('system.roles.edit');

    Route::get('/sistem/pengguna', Users::class)->name('system.users');
    Route::get('/sistem/pengguna/create', UserForm::class)->name('system.users.create');
    Route::get('/sistem/pengguna/{user}/edit', UserForm::class)->name('system.users.edit');
});
