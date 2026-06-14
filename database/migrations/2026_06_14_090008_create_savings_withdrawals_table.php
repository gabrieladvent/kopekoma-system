<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_withdrawals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('withdrawal_number', 25)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignUuid('member_id')->constrained('members');
            $table->enum('savings_type', ['hari_raya', 'sukarela', 'swp', 'tabungan_berjangka', 'pokok', 'wajib'])->index();
            $table->decimal('amount', 18, 2);
            $table->date('withdrawal_date');
            $table->foreignUuid('related_loan_id')->nullable()->constrained('loans');
            $table->text('notes')->nullable();
            $table->boolean('is_reversal')->default(false);
            $table->foreignUuid('reversal_of_id')->nullable()->constrained('savings_withdrawals');
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_withdrawals');
    }
};
