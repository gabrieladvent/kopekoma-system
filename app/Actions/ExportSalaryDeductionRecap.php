<?php

namespace App\Actions;

use App\Models\Agency;
use App\Models\SavingsDeposit;
use App\Services\BatchSalaryDeductionService;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportSalaryDeductionRecap
{
    public function __invoke(Agency $agency, string $periodMonth, ?int $causerId = null): StreamedResponse
    {
        $causerId ??= auth()->id();

        $period = Carbon::parse($periodMonth)->startOfMonth()->toDateString();

        $deposits = SavingsDeposit::query()
            ->where('savings_type', BatchSalaryDeductionService::SAVINGS_TYPE)
            ->where('deposit_method', BatchSalaryDeductionService::METHOD)
            ->whereDate('period_month', $period)
            ->where('is_reversal', false)
            ->whereHas('member', fn ($q) => $q->where('agency_id', $agency->getKey()))
            ->with('member')
            ->orderBy('transaction_number')
            ->get();

        activity()
            ->causedBy($causerId)
            ->event('export')
            ->withProperties([
                'agency_id' => $agency->getKey(),
                'period_month' => $period,
                'rows' => $deposits->count(),
            ])
            ->log("Export rekap potong gaji OPD {$agency->agency_name} periode {$period}: {$deposits->count()} baris");

        $filename = 'rekap-potong-gaji-'.$agency->agency_code.'-'.Carbon::parse($period)->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($deposits): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['No. Transaksi', 'No. Anggota', 'Nama', 'Nominal', 'Tanggal Setor', 'Periode']);

            foreach ($deposits as $deposit) {
                fputcsv($out, [
                    $deposit->transaction_number,
                    $deposit->member?->member_number,
                    $deposit->member?->full_name,
                    $deposit->amount,
                    optional($deposit->deposit_date)->format('Y-m-d'),
                    optional($deposit->period_month)->format('Y-m'),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
