<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_transactions', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->unique()->after('id');
            $table->string('transaction_number', 25)->nullable()->unique()->after('idempotency_key');

            $table->unique('reversal_of_id', 'shopping_transactions_reversal_of_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shopping_transactions', function (Blueprint $table) {
            $table->index('reversal_of_id', 'shopping_transactions_reversal_of_id_index');
            $table->dropUnique('shopping_transactions_reversal_of_id_unique');
            $table->dropUnique(['transaction_number']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'transaction_number']);
        });
    }
};
