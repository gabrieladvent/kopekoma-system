<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Memindahkan media yang terlanjur tersimpan di disk `public` ke disk privat.
 *
 * Latar: disk media dulu default ke `public`, sehingga dokumen anggota (KTP/SK),
 * kuitansi, dan bukti bayar dapat diunduh siapa pun lewat /storage/... tanpa
 * autentikasi. Konfigurasi sudah diperbaiki untuk unggahan BARU; berkas lama
 * tetap tertinggal di lokasi publik sampai command ini dijalankan.
 *
 * Selalu jalankan --dry-run lebih dulu, dan pastikan ada backup. Perpindahan file
 * tidak reversibel lewat command ini.
 */
class MigrateMediaToPrivateDisk extends Command
{
    protected $signature = 'media:migrate-to-private
        {--dry-run : Tampilkan yang akan dipindahkan tanpa menyentuh berkas}
        {--from=public : Disk asal}
        {--to=local : Disk tujuan}';

    protected $description = 'Pindahkan media dari disk publik ke disk privat (dokumen anggota, kuitansi, bukti bayar)';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $dry = (bool) $this->option('dry-run');

        $media = Media::query()->where('disk', $from)->get();

        if ($media->isEmpty()) {
            $this->info("Tidak ada media di disk '{$from}'. Tidak ada yang perlu dipindahkan.");

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            '%s %d berkas dari disk "%s" ke "%s".',
            $dry ? '[DRY RUN] Akan memindahkan' : 'Memindahkan',
            $media->count(),
            $from,
            $to,
        ));

        if (! $dry && ! $this->confirm('Sudah punya backup database DAN direktori storage?', false)) {
            $this->error('Dibatalkan. Ambil backup dulu.');

            return self::FAILURE;
        }

        $moved = 0;
        $missing = 0;
        $failed = 0;

        foreach ($media as $item) {
            $path = $item->getPathRelativeToRoot();

            if (! Storage::disk($from)->exists($path)) {
                $this->line("  <fg=yellow>hilang</> {$path} (baris DB dibiarkan apa adanya)");
                $missing++;

                continue;
            }

            if ($dry) {
                $this->line("  <fg=cyan>akan pindah</> {$path}");
                $moved++;

                continue;
            }

            try {
                // Salin dulu, perbarui baris DB, baru hapus sumbernya. Kalau proses
                // mati di tengah, berkas masih dapat diakses lewat salah satu disk —
                // lebih baik daripada baris DB yang menunjuk ke berkas yang hilang.
                Storage::disk($to)->writeStream($path, Storage::disk($from)->readStream($path));

                $item->disk = $to;
                $item->conversions_disk = $to;
                $item->save();

                Storage::disk($from)->delete($path);

                $this->line("  <fg=green>pindah</> {$path}");
                $moved++;
            } catch (Throwable $e) {
                $this->line("  <fg=red>gagal</> {$path} — {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Selesai. Dipindahkan: {$moved}, hilang: {$missing}, gagal: {$failed}.");

        if (! $dry && $failed === 0) {
            $this->newLine();
            $this->warn('Langkah lanjutan manual:');
            $this->line('  1. Hapus sisa direktori kosong di storage/app/public.');
            $this->line('  2. Anggap berkas yang sempat terekspos sebagai insiden kebocoran data.');
            $this->line('  3. Pastikan MEDIA_DISK tidak di-set ke "public" di .env produksi.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
