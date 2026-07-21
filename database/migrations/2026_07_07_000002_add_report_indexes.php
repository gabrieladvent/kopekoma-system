<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->index('payment_date', 'installments_payment_date_index');
        });

        Schema::table('savings_deposits', function (Blueprint $table) {
            $table->index('deposit_date', 'savings_deposits_deposit_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropIndex('installments_payment_date_index');
        });

        Schema::table('savings_deposits', function (Blueprint $table) {
            $table->dropIndex('savings_deposits_deposit_date_index');
        });
    }
};
