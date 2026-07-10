<?php

namespace App\Filament\Resources\SavingsWithdrawalResource\Pages;

use App\Filament\Resources\SavingsWithdrawalResource;
use App\Models\SavingsWithdrawal;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSavingsWithdrawal extends ViewRecord
{
    protected static string $resource = SavingsWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (): bool => $this->getRecord()->status === 'draft'),
            Actions\Action::make('approve')
                ->label('ACC')
                ->icon('heroicon-o-check-circle')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Setujui Pencairan')
                ->modalDescription('Menyetujui pengajuan ini. Dana belum keluar sampai dicairkan.')
                ->visible(fn (): bool => $this->getRecord()->status === 'draft'
                    && (auth()->user()?->can('approve', $this->getRecord()) ?? false))
                ->action(fn () => $this->runTransition('approve')),
            Actions\Action::make('disburse')
                ->label('Cairkan')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Cairkan Dana')
                ->modalDescription('Menandai pencairan sebagai cair. Saldo anggota akan berkurang.')
                ->visible(fn (): bool => $this->getRecord()->status === 'acc'
                    && (auth()->user()?->can('disburse', $this->getRecord()) ?? false))
                ->action(fn () => $this->runTransition('disburse')),
            Actions\Action::make('reject')
                ->label('Tolak')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Tolak Pencairan')
                ->modalDescription('Menolak pengajuan. Status ditolak bersifat final.')
                ->visible(fn (): bool => in_array($this->getRecord()->status, ['draft', 'acc'], true)
                    && (auth()->user()?->can('approve', $this->getRecord()) ?? false))
                ->action(fn () => $this->runTransition('reject')),
            Actions\Action::make('reverse')
                ->label('Reversal')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->form(SavingsWithdrawalResource::reverseFormSchema())
                ->requiresConfirmation()
                ->modalHeading('Reversal Pencairan')
                ->modalDescription('Membuat transaksi-lawan. Baris asli tidak dihapus.')
                ->visible(fn (): bool => SavingsWithdrawalResource::canReverse($this->getRecord()))
                ->action(function (array $data): void {
                    /** @var SavingsWithdrawal $record */
                    $record = $this->getRecord();

                    SavingsWithdrawalResource::performReversal($record, $data);

                    $this->refreshRecord();
                }),
        ];
    }

    protected function runTransition(string $transition): void
    {
        SavingsWithdrawalResource::runTransition($transition, $this->getRecord());

        $this->refreshRecord();
    }

    protected function refreshRecord(): void
    {
        $this->record->refresh();
    }
}
