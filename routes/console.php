<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('loans:remind-installments')
    ->dailyAt('05:00')
    ->timezone('Asia/Jakarta');

// Backup harian sebelum jam kerja. withoutOverlapping supaya dump yang lambat
// tidak bertumpuk dengan jadwal berikutnya; onOneServer aman meski saat ini
// single-server. Simpan 14 hari terakhir.
Schedule::command('db:backup --keep=14')
    ->dailyAt('02:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();
