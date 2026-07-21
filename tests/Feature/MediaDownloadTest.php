<?php

use App\Models\Member;
use App\Models\User;
use App\Support\MediaFileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Dokumen anggota (KTP/SK) adalah PII finansial. Sebelumnya berkas ini disimpan
 * di disk `public` dan dirender lewat getFullUrl(), sehingga dapat diunduh siapa
 * pun tanpa akun lewat URL /storage/... yang bisa ditebak — seluruh gate
 * `can:view_member` terlewati begitu saja.
 *
 * Tes-tes ini mengunci perilaku penggantinya: berkas hanya boleh keluar lewat
 * route ber-otorisasi.
 */
function attachDocument(Member $member, string $originalName = 'ktp-asli.jpg'): Media
{
    // image(), bukan create(): create() menghasilkan berkas berisi byte nol yang
    // terdeteksi sebagai application/x-empty dan ditolak validasi mime koleksi.
    $file = UploadedFile::fake()->image($originalName, 10, 10);

    return $member
        ->addMedia($file)
        ->usingFileName(MediaFileName::for($file))
        ->usingName(pathinfo($originalName, PATHINFO_FILENAME))
        ->toMediaCollection('documents');
}

it('stores member documents on a private disk, never the public one', function () {
    asSuperAdmin();
    $media = attachDocument(Member::factory()->create());

    expect($media->disk)->not->toBe('public')
        ->and(config('media-library.disk_name'))->not->toBe('public');
});

it('does not keep the original client filename on disk', function () {
    asSuperAdmin();

    $media = attachDocument(Member::factory()->create(), 'kartu-anggota-KM-2026-0002.jpg');

    // Nama file yang tersimpan tidak boleh mengandung nomor anggota — itulah yang
    // membuat berkas dapat dienumerasi dari luar.
    expect($media->file_name)->not->toContain('KM-2026')
        ->and($media->name)->toBe('kartu-anggota-KM-2026-0002');
});

it('refuses to serve a document to a guest', function () {
    asSuperAdmin();
    $media = attachDocument(Member::factory()->create());

    auth()->logout();

    $this->get(route('media.show', $media))->assertRedirect(route('login'));
});

it('refuses to serve a document to a user without permission to view the member', function () {
    asSuperAdmin();
    $media = attachDocument(Member::factory()->create());

    // User tanpa peran apa pun — tidak punya view_member.
    $this->actingAs(User::factory()->create());

    $this->get(route('media.show', $media))->assertForbidden();
});

it('serves the document to a user allowed to view the member', function () {
    asSuperAdmin();
    $media = attachDocument(Member::factory()->create());

    asPetugas();

    $this->get(route('media.show', $media))->assertOk();
});

it('records an audit entry when a document is downloaded', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    $media = attachDocument($member);

    $user = asPetugas();

    $this->get(route('media.show', $media))->assertOk();

    // Kebocoran PII lewat disk publik dulu tidak meninggalkan jejak sama sekali;
    // sekarang setiap pengambilan berkas tercatat.
    $this->assertDatabaseHas('activity_log', [
        'event' => 'media_diakses',
        'subject_type' => $member->getMorphClass(),
        'subject_id' => $member->id,
        'causer_id' => $user->id,
    ]);
});

it('returns 404 when the owning model no longer exists', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    $media = attachDocument($member);

    $member->forceDelete();

    $this->get(route('media.show', $media))->assertNotFound();
});

beforeEach(function () {
    Storage::fake('local');
});
