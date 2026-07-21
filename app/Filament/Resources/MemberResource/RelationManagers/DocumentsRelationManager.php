<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Support\MediaFileName;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Dokumen';

    protected static ?string $icon = 'heroicon-o-paper-clip';

    protected static ?string $modelLabel = 'dokumen';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    protected static function fileIconUri(): string
    {
        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
        </svg>
        SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('file_name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('collection_name', 'documents'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('preview')
                    ->label('Pratinjau')
                    ->height(48)
                    ->getStateUsing(fn (Media $record): string => str_starts_with((string) $record->mime_type, 'image/')
                        ? route('media.show', $record)
                        : static::fileIconUri()),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->getStateUsing(fn (Media $record): string => $record->file_name),
                Tables\Columns\TextColumn::make('mime_type')
                    ->label('Jenis')
                    ->badge()
                    ->color(fn (string $state): string => str_starts_with($state, 'image/') ? 'info' : 'warning')
                    ->formatStateUsing(fn (string $state): string => str_starts_with($state, 'image/') ? 'Gambar' : 'PDF'),
                Tables\Columns\TextColumn::make('human_readable_size')
                    ->label('Ukuran'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diunggah')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('upload')
                    ->label('Unggah Dokumen')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        Forms\Components\FileUpload::make('files')
                            ->label('Berkas')
                            ->multiple()
                            ->required()
                            ->storeFiles(false)
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                            ->maxSize(5120)
                            ->helperText('KTP, SK, formulir, dll. PDF/JPG/PNG, maks 5 MB per berkas.'),
                    ])
                    ->action(function (array $data): void {
                        $owner = $this->getOwnerRecord();
                        $names = [];

                        foreach ($data['files'] as $file) {
                            $owner->addMedia($file->getRealPath())
                                ->usingFileName(MediaFileName::for($file))
                                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                                ->toMediaCollection('documents');
                            $names[] = $file->getClientOriginalName();
                        }

                        $owner->logDocumentActivity(count($names) === 1
                            ? 'Mengunggah dokumen: '.$names[0]
                            : 'Mengunggah '.count($names).' dokumen');

                        Notification::make()
                            ->success()
                            ->title('Dokumen diunggah')
                            ->body(count($names).' berkas berhasil ditambahkan.')
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label('Lihat')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Media $record): string => route('media.show', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('download')
                        ->label('Unduh')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (Media $record): string => route('media.show', $record))
                        ->openUrlInNewTab(),
                    Tables\Actions\DeleteAction::make()
                        ->label('Hapus')
                        ->after(fn (Media $record) => $this->getOwnerRecord()
                            ->logDocumentActivity('Menghapus dokumen: '.$record->file_name)),
                ]),
            ])
            ->emptyStateHeading('Belum ada dokumen')
            ->emptyStateDescription('Unggah KTP, SK, atau berkas pendukung lain.')
            ->emptyStateIcon('heroicon-o-paper-clip');
    }
}
