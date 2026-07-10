<?php

namespace App\Jobs;

use App\Imports\MembersImport;
use App\Models\Member;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportMembersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $path,
        protected string $disk,
        protected int $userId,
    ) {}

    public function handle(): void
    {
        $before = Member::count();

        $import = new MembersImport;

        Excel::import($import, $this->path, $this->disk);

        $imported = Member::count() - $before;

        $failed = $import->failures()->count() + $import->errors()->count();

        Storage::disk($this->disk)->delete($this->path);

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $notification = Notification::make()
            ->title('Import anggota selesai')
            ->body($imported.' anggota berhasil diimport'
                .($failed > 0 ? ', '.$failed.' baris dilewati karena tidak valid.' : '.'));

        ($failed > 0 ? $notification->warning() : $notification->success())
            ->sendToDatabase($user, isEventDispatched: true);
    }
}
