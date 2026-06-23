<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            // Alasan reversal (dipakai ReverseTransaction generik) + audit koreksi.
            $table->text('notes')->nullable()->after('payment_method');
            $table->unique('reversal_of_id', 'installments_reversal_of_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->index('reversal_of_id', 'installments_reversal_of_id_index');
            $table->dropUnique('installments_reversal_of_id_unique');
            $table->dropColumn('notes');
        });
    }
};
