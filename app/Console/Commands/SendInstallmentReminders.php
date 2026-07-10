<?php

namespace App\Console\Commands;

use App\Models\InstallmentSchedule;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SendInstallmentReminders extends Command
{
    protected $signature = 'loans:remind-installments {--days=3 : Berapa hari sebelum jatuh tempo mulai diingatkan}';

    protected $description = 'Kirim pengingat angsuran yang akan jatuh tempo (H-N) & yang nunggak.';

    public function handle(): int
    {
        $leadDays = max(0, (int) $this->option('days'));

        $today = Carbon::today();

        $windowEnd = $today->copy()->addDays($leadDays);

        $recipients = User::query()->get()
            ->filter(fn (User $user): bool => $user->can('view_any_installment'))
            ->values();

        if ($recipients->isEmpty()) {
            $this->warn('Tidak ada penerima (user dengan izin view_any_installment). Lewati.');

            return self::SUCCESS;
        }

        $upcoming = $this->sendUpcoming($recipients, $today, $windowEnd);

        $overdue = $this->sendOverdue($recipients, $today);

        $this->info("Pengingat terkirim — akan jatuh tempo: {$upcoming}, nunggak: {$overdue} (ke {$recipients->count()} petugas).");

        return self::SUCCESS;
    }

    private function sendUpcoming(Collection $recipients, Carbon $today, Carbon $windowEnd): int
    {
        $schedules = InstallmentSchedule::query()
            ->with(['loan.member'])
            ->where('status', 'Belum Bayar')
            ->whereNull('due_reminder_sent_at')
            ->whereDate('due_date', '>=', $today)
            ->whereDate('due_date', '<=', $windowEnd)
            ->whereHas('loan', fn ($q) => $q->where('status', 'Cair'))
            ->orderBy('due_date')
            ->get();

        foreach ($schedules as $schedule) {
            $daysLeft = (int) $today->diffInDays($schedule->due_date, false);

            $when = $daysLeft <= 0 ? 'hari ini' : "{$daysLeft} hari lagi";

            Notification::make()
                ->warning()
                ->icon('heroicon-o-clock')
                ->title('Angsuran akan jatuh tempo')
                ->body($this->body($schedule, "jatuh tempo {$when}"))
                ->actions([$this->viewAction($schedule)])
                ->sendToDatabase($recipients);

            $schedule->forceFill(['due_reminder_sent_at' => now()])->save();
        }

        return $schedules->count();
    }

    /**
     * Angsuran Belum Bayar yang sudah lewat tempo (nunggak). Berbeda dgn pengingat
     * jatuh-tempo yang sekali saja, tunggakan DIINGATKAN BERULANG selama belum
     * dibayar: syaratnya belum pernah diingatkan ATAU terakhir diingatkan sebelum
     * hari ini. Jadi tiap kali command berjalan (harian), tunggakan yang masih
     * berdiri kembali muncul — dan otomatis berhenti begitu status bukan lagi
     * "Belum Bayar" (sudah dibayar).
     */
    private function sendOverdue(Collection $recipients, Carbon $today): int
    {
        $schedules = InstallmentSchedule::query()
            ->with(['loan.member'])
            ->where('status', 'Belum Bayar')
            ->where(function ($query) use ($today) {
                $query->whereNull('overdue_reminder_sent_at')
                    ->orWhereDate('overdue_reminder_sent_at', '<', $today);
            })
            ->whereDate('due_date', '<', $today)
            ->whereHas('loan', fn ($query) => $query->where('status', 'Cair'))
            ->orderBy('due_date')
            ->get();

        foreach ($schedules as $schedule) {
            $daysLate = (int) $schedule->due_date->diffInDays($today, false);

            Notification::make()
                ->danger()
                ->icon('heroicon-o-exclamation-triangle')
                ->title('Angsuran nunggak')
                ->body($this->body($schedule, "lewat tempo {$daysLate} hari, belum dibayar"))
                ->actions([$this->viewAction($schedule)])
                ->sendToDatabase($recipients);

            $schedule->forceFill(['overdue_reminder_sent_at' => now()])->save();
        }

        return $schedules->count();
    }

    private function viewAction(InstallmentSchedule $schedule): Action
    {
        return Action::make('lihat')
            ->label('Lihat pinjaman')
            ->url(route('loans.show', $schedule->loan_id))
            ->markAsRead();
    }

    private function body(InstallmentSchedule $schedule, string $state): string
    {
        $member = $schedule->loan?->member?->full_name ?? 'Anggota';

        $loanNumber = $schedule->loan?->loan_number ?? '-';

        $due = $schedule->due_date?->translatedFormat('d M Y');

        $amount = number_format((float) $schedule->total_due, 0, ',', '.');

        return "{$member} — angsuran ke-{$schedule->installment_seq} pinjaman {$loanNumber} {$state} ({$due}). Tagihan Rp {$amount}.";
    }
}
