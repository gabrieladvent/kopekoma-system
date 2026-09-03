<?php

use App\Livewire\System\ActivityLogs;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Properti log berbentuk array — `skipped_rows` pada batch potong gaji.
 *
 * Panel audit melempar SEMUA nilai ke `(string)`, jadi properti array memicu
 * "Array to string conversion" dan MEMATIKAN seluruh halaman Log Aktivitas.
 * Bukan cuma satu baris yang hilang: log adalah salah satu dari tiga kanal
 * pendeteksian syarat diterimanya R14, dan entri yang menjatuhkannya justru
 * yang paling perlu dibaca — daftar anggota yang gajinya terpotong tapi
 * angsurannya tak tercatat (UAT §6.1).
 */
function batchActivity(array $skippedRows): int
{
    activity()
        ->event('batch_angsuran_potong_gaji')
        ->withProperties(['attributes' => [
            'created' => 3,
            'skipped' => count($skippedRows),
            'skipped_rows' => $skippedRows,
        ]])
        ->log('Batch potong gaji angsuran');

    return (int) Activity::query()->latest('id')->value('id');
}

it('renders a list of skipped rows instead of crashing', function () {
    asPengurus();

    $id = batchActivity([
        [
            'schedule_id' => '9c1f',
            'loan_number' => 'PJM-2026-000011',
            'member' => 'Rahayu Kusumaningrum',
            'reason' => 'Jadwal sudah terbayar',
        ],
        [
            'schedule_id' => '9c20',
            'loan_number' => 'PJM-2026-000012',
            'member' => 'Bagus Prakoso',
            'reason' => 'Bukan anggota OPD ini',
        ],
    ]);

    $rows = collect(
        Livewire::test(ActivityLogs::class)->instance()
            ->auditDiff(Activity::find($id))
    )->keyBy('label');

    // Nomor pinjaman, nama anggota, DAN sebabnya — bukan sekadar angka.
    expect($rows['Skipped rows']['new'])
        ->toContain('PJM-2026-000011', 'Rahayu Kusumaningrum', 'Jadwal sudah terbayar')
        ->toContain('PJM-2026-000012', 'Bagus Prakoso', 'Bukan anggota OPD ini');

    // Satu baris per elemen, bukan satu gumpalan.
    expect(substr_count($rows['Skipped rows']['new'], "\n"))->toBe(1);

    expect($rows['Skipped']['new'])->toBe('2');
});

it('opens the activity log page that used to fatal', function () {
    asPengurus();

    batchActivity([[
        'schedule_id' => '9c1f',
        'loan_number' => 'PJM-2026-000011',
        'member' => 'Rahayu Kusumaningrum',
        'reason' => 'Jadwal sudah terbayar',
    ]]);

    $this->get(route('system.activity-logs'))->assertOk();
});

/** Baris tanpa jadwal (payload diutak-atik) tak boleh menyisakan sel kosong. */
it('drops null parts instead of printing empty separators', function () {
    asPengurus();

    $id = batchActivity([[
        'schedule_id' => '9c1f',
        'loan_number' => null,
        'member' => null,
        'reason' => 'Jadwal angsuran tidak ditemukan',
    ]]);

    $diff = collect(
        Livewire::test(ActivityLogs::class)->instance()
            ->auditDiff(Activity::find($id))
    )->keyBy('label');

    expect($diff['Skipped rows']['new'])->toBe('9c1f · Jadwal angsuran tidak ditemukan');
});

/** Array kosong: tak ada yang dilewati — jangan tampilkan "[]". */
it('shows a dash when nothing was skipped', function () {
    asPengurus();

    $id = batchActivity([]);

    $diff = collect(
        Livewire::test(ActivityLogs::class)->instance()
            ->auditDiff(Activity::find($id))
    )->keyBy('label');

    expect($diff['Skipped rows']['new'])->toBe('—');
});
