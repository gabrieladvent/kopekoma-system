<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Simpanan Pokok dibayar sekali seumur keanggotaan (saat masuk).
            // `true` → form & batch menyembunyikan/mengunci jenis Pokok.
            $table->boolean('pokok_paid')->default(false)->after('mandatory_savings_amount');
        });

        // Backfill: anggota yang sudah punya setoran pokok AKTIF (net > 0) → true.
        $paidMemberIds = DB::table('savings_deposits')
            ->where('savings_type', 'pokok')
            ->groupBy('member_id')
            ->havingRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount ELSE -amount END), 0) > 0')
            ->pluck('member_id');

        if ($paidMemberIds->isNotEmpty()) {
            DB::table('members')->whereIn('id', $paidMemberIds)->update(['pokok_paid' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('pokok_paid');
        });
    }
};
