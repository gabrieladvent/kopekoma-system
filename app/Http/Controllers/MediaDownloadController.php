<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Satu-satunya jalan mengambil file media (dokumen anggota, kuitansi, bukti bayar).
 *
 * Sebelumnya media disimpan di disk `public` dan view merender getUrl()/getFullUrl()
 * — URL permanen tanpa autentikasi di /storage/{id}/{nama-asli}. Karena id direktori
 * berurutan dan nama file mempertahankan nama asli klien (mis.
 * kartu-anggota-KM-2026-0002.pdf), seluruh registri anggota bisa dipanen siapa pun
 * dari internet tanpa akun, dan gate `can:view_member` sepenuhnya terlewati.
 *
 * Sekarang: disk privat + route ini, yang mengecek policy model pemilik media dan
 * mencatat setiap akses ke activity log.
 */
class MediaDownloadController extends Controller
{
    public function __invoke(Media $media): StreamedResponse
    {
        $owner = $media->model;

        if ($owner === null) {
            abort(404);
        }

        // Otorisasi mengikuti policy model pemiliknya — dokumen anggota mewarisi
        // MemberPolicy@view, bukti angsuran mewarisi InstallmentPolicy@view, dst.
        if (! Gate::allows('view', $owner)) {
            throw new AuthorizationException;
        }

        activity()
            ->performedOn($owner)
            ->causedBy(auth()->user())
            ->event('media_diakses')
            ->withProperties([
                'media_id' => $media->getKey(),
                'file_name' => $media->file_name,
                'collection' => $media->collection_name,
            ])
            ->log('Mengunduh berkas: '.$media->file_name);

        return $media->toResponse(request());
    }
}
