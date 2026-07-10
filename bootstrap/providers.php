<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Panel admin Filament dinonaktifkan sementara — UI dilayani oleh Livewire
    // (lihat routes/web.php). Kelas Filament TETAP ada & method statisnya (mis.
    // LoanResource::printReceipt) masih dipakai route Livewire untuk cetak PDF.
    // Aktifkan lagi dengan mengembalikan baris di bawah:
    // \App\Providers\Filament\AdminPanelProvider::class,
];
