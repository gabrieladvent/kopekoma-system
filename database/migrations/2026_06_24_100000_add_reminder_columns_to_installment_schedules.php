<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda agar pengingat angsuran (H-3 jatuh tempo & nunggak) dikirim
     * tepat sekali per jadwal — bukan tiap kali scheduler harian jalan.
     */
    public function up(): void
    {
        Schema::table('installment_schedules', function (Blueprint $table) {
            $table->timestamp('due_reminder_sent_at')->nullable()->after('status');
            $table->timestamp('overdue_reminder_sent_at')->nullable()->after('due_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('installment_schedules', function (Blueprint $table) {
            $table->dropColumn(['due_reminder_sent_at', 'overdue_reminder_sent_at']);
        });
    }
};
