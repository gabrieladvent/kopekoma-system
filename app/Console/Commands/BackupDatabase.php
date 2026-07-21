<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Dump database harian ke disk lokal.
 *
 * Sebelum ini sistem tidak punya backup sama sekali — satu-satunya salinan
 * catatan simpanan & pinjaman anggota adalah database live itu sendiri. Untuk
 * koperasi yang memegang uang orang lain, itu persoalan kepatuhan, bukan cuma
 * teknis.
 *
 * Ini sengaja minimal dan tanpa dependensi baru. Kalau nanti butuh rotasi
 * off-site/S3 dan notifikasi kegagalan, pertimbangkan spatie/laravel-backup.
 *
 * PENTING: backup yang belum pernah dipulihkan belum terbukti ada. Uji restore
 * secara berkala ke database staging.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep=14 : Jumlah berkas backup yang dipertahankan}';

    protected $description = 'Dump database ke storage/app/private/backups';

    public function handle(): int
    {
        $connection = config('database.default');

        if ($connection !== 'mysql') {
            $this->error("db:backup hanya mendukung MySQL, koneksi saat ini: {$connection}.");

            return self::FAILURE;
        }

        $config = config("database.connections.{$connection}");
        $directory = storage_path('app/private/backups');

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            $this->error("Tidak bisa membuat direktori backup: {$directory}");

            return self::FAILURE;
        }

        $file = $directory.'/'.$config['database'].'-'.now()->format('Y-m-d-His').'.sql.gz';

        // Password lewat MYSQL_PWD, bukan argumen --password: argumen proses
        // terlihat di `ps` oleh user lain di server yang sama.
        $process = Process::fromShellCommandline(
            'mysqldump --single-transaction --routines --triggers '
            .'--host=${:DB_HOST} --port=${:DB_PORT} --user=${:DB_USER} ${:DB_NAME} '
            .'| gzip > ${:DB_FILE}'
        );

        $process->setEnv([
            'MYSQL_PWD' => (string) $config['password'],
            'DB_HOST' => (string) $config['host'],
            'DB_PORT' => (string) $config['port'],
            'DB_USER' => (string) $config['username'],
            'DB_NAME' => (string) $config['database'],
            'DB_FILE' => $file,
        ]);

        $process->setTimeout(3600);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            @unlink($file);

            Log::error('Backup database GAGAL', ['error' => $e->getMessage()]);
            $this->error('Backup gagal: '.$process->getErrorOutput());

            return self::FAILURE;
        }

        if (! is_file($file) || filesize($file) === 0) {
            @unlink($file);

            Log::error('Backup database menghasilkan berkas kosong', ['file' => $file]);
            $this->error('Backup menghasilkan berkas kosong — dianggap gagal.');

            return self::FAILURE;
        }

        $this->info('Backup dibuat: '.$file.' ('.$this->humanSize(filesize($file)).')');

        $this->prune($directory, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    private function prune(string $directory, int $keep): void
    {
        $files = glob($directory.'/*.sql.gz') ?: [];

        if (count($files) <= $keep) {
            return;
        }

        // Terbaru dulu, buang sisanya.
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            $this->line('  dibuang: '.basename($old));
        }
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }
            $bytes = (int) ($bytes / 1024);
        }

        return $bytes.' TB';
    }
}
