<?php

use App\Actions\ExportSalaryDeductionRecap;
use App\Filament\Resources\SavingsDepositResource;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Master\Agency\Agencies;
use App\Livewire\Master\Agency\AgencyDetail;
use App\Livewire\Master\Grade\GradeDetail;
use App\Livewire\Master\Grade\Grades;
use App\Livewire\Master\Member\MemberDetail;
use App\Livewire\Master\Member\MemberForm;
use App\Livewire\Master\Member\Members;
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
use App\Livewire\Settings\ManageSettings;
use App\Livewire\System\ActivityLogs;
use App\Livewire\System\RoleForm;
use App\Livewire\System\Roles;
use App\Models\Agency;
use App\Models\Member;
use App\Models\SavingsDeposit;
use Barryvdh\DomPDF\Facade\Pdf;
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

    // Simpanan — Saldo Anggota (rekap read-only; gating di mount()).
    Route::get('/simpanan/saldo-anggota', MemberBalances::class)
        ->name('savings.balances');

    Route::get('/simpanan/saldo-anggota/{member}', MemberSavingsDetail::class)
        ->name('savings.balances.show');

    Route::get('/settings', ManageSettings::class)
        ->middleware('can:manage_settings')
        ->name('settings');

    // Sistem — gating ditegakkan di mount() tiap komponen.
    Route::get('/sistem/log-aktivitas', ActivityLogs::class)->name('system.activity-logs');

    Route::get('/sistem/peran', Roles::class)->name('system.roles');
    Route::get('/sistem/peran/create', RoleForm::class)->name('system.roles.create');
    Route::get('/sistem/peran/{role}/edit', RoleForm::class)->name('system.roles.edit');
});
