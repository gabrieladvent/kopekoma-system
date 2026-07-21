@php
    /** @var \App\Models\Installment $record */
    $record = $getRecord();
    $media = $record->getFirstMedia('bukti');
    // Route ber-otorisasi, bukan getUrl(): media disimpan di disk privat.
    $url = $media ? route('media.show', $media) : null;
    $mime = (string) ($media?->mime_type ?? '');
    $ext = strtolower(pathinfo((string) ($media?->file_name ?? ''), PATHINFO_EXTENSION));
    // Fallback ke ekstensi: mime hasil deteksi kadang tak persis (mis. file kosong).
    $isImage = str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    $isPdf = $mime === 'application/pdf' || $ext === 'pdf';
@endphp

@if (!$media)
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Tidak ada bukti yang diunggah untuk angsuran ini.
    </p>
@elseif ($isImage)
    {{-- Gambar: tampil ukuran wajar; klik buka ukuran penuh di tab baru. --}}
    <a href="{{ $url }}" target="_blank" rel="noopener" class="inline-block space-y-2">
        <img src="{{ $url }}" alt="Bukti pembayaran {{ $record->installment_number }}"
            class="h-auto max-h-64 w-auto max-w-xs rounded-lg object-contain ring-1 ring-gray-200 transition hover:opacity-90 dark:ring-white/10" />
        <span class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
            Klik untuk buka ukuran penuh di tab baru.
        </span>
    </a>
@elseif ($isPdf)
    {{-- PDF: buka di tab baru (browser merender PDF natif). --}}
    <a href="{{ $url }}" target="_blank" rel="noopener"
        class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-primary-500">
        <x-heroicon-o-document-text class="h-5 w-5" />
        Buka bukti (PDF) di tab baru
    </a>
@else
    {{-- Tipe lain: cukup tautan unduh. --}}
    <a href="{{ $url }}" target="_blank" rel="noopener"
        class="inline-flex items-center gap-2 text-sm font-medium text-primary-600 underline dark:text-primary-400">
        <x-heroicon-o-arrow-down-tray class="h-5 w-5" />
        Unduh bukti
    </a>
@endif
