<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->string('payment_method')->change();
        });

        Schema::table('savings_withdrawals', function (Blueprint $table) {
            $table->string('disbursement_method')->nullable()->change();

            $table->foreignUuid('installment_id')->nullable()->after('related_loan_id')->constrained('installments');
        });
    }

    public function down(): void
    {
        Schema::table('savings_withdrawals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('installment_id');

            $table->enum('disbursement_method', ['tunai', 'transfer'])->nullable()->change();
        });

        Schema::table('installments', function (Blueprint $table) {
            $table->enum('payment_method', ['potong_gaji', 'manual'])->change();
        });
    }
};
