<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Exports\MembersTemplateExport;
use App\Filament\Resources\MemberResource;
use App\Jobs\ImportMembersJob;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('downloadTemplate')
                ->label('Unduh Template')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => MemberResource::canImportMembers())
                ->action(fn (): BinaryFileResponse => Excel::download(
                    new MembersTemplateExport,
                    'template-import-anggota.xlsx',
                )),
            Actions\Action::make('import')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn (): bool => MemberResource::canImportMembers())
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label('Berkas Excel / CSV')
                        ->required()
                        ->storeFiles(false)
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                        ])
                        ->helperText('Gunakan template di atas. Nomor anggota digenerate sistem. Proses berjalan di latar belakang.'),
                ])
                ->action(function (array $data): void {
                    // Simpan berkas ke disk agar bisa dibaca worker antrian,
                    // lalu proses import di background.
                    $file = $data['file'];
                    $path = $file->storeAs(
                        'imports/members',
                        Str::uuid().'-'.$file->getClientOriginalName(),
                        'local',
                    );

                    ImportMembersJob::dispatch($path, 'local', auth()->id());

                    Notification::make()
                        ->info()
                        ->title('Import sedang diproses')
                        ->body('Berkas sedang diproses di latar belakang. Anda akan diberi notifikasi saat selesai.')
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
