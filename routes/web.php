<?php

use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Master\Agencies;
use App\Livewire\Master\AgencyDetail;
use App\Livewire\Master\GradeDetail;
use App\Livewire\Master\Grades;
use App\Livewire\Master\MemberDetail;
use App\Livewire\Master\MemberForm;
use App\Livewire\Master\Members;
use App\Livewire\Settings\ManageSettings;
use App\Models\Member;
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

    Route::get('/settings', ManageSettings::class)
        ->middleware('can:manage_settings')
        ->name('settings');
});
