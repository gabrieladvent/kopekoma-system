<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_deposits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('transaction_number', 25)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignUuid('member_id')->constrained('members');
            $table->enum('savings_type', ['pokok', 'wajib', 'hari_raya', 'wajib_belanja', 'sukarela'])->index();
            $table->decimal('amount', 18, 2);
            $table->date('deposit_date');
            $table->date('period_month')->nullable()->index();
            $table->enum('deposit_method', ['potong_gaji', 'setor_sendiri']);
            $table->enum('deposited_by', ['bendahara', 'anggota']);
            $table->string('reference_number', 50)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_reversal')->default(false);
            $table->foreignUuid('reversal_of_id')->nullable()->constrained('savings_deposits');
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['member_id', 'savings_type', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_deposits');
    }
};
