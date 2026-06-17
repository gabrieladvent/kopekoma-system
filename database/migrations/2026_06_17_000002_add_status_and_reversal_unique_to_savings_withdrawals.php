<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_withdrawals', function (Blueprint $table) {
            $table->enum('status', ['draft', 'acc', 'cair', 'ditolak'])->default('draft')->after('withdrawal_date');
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->timestamp('disbursed_at')->nullable()->after('approved_at');
            $table->unsignedSmallInteger('period_year')->nullable()->after('disbursed_at');

            $table->unique('reversal_of_id', 'savings_withdrawals_reversal_of_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('savings_withdrawals', function (Blueprint $table) {
            $table->index('reversal_of_id', 'savings_withdrawals_reversal_of_id_index');
            $table->dropUnique('savings_withdrawals_reversal_of_id_unique');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['status', 'approved_at', 'disbursed_at', 'period_year']);
        });
    }
};
