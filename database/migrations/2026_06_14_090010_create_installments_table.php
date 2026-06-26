<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('installment_number', 25)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignUuid('loan_id')->constrained('loans');
            $table->foreignId('schedule_id')->nullable()->constrained('installment_schedules');
            $table->unsignedSmallInteger('installment_seq');
            $table->date('payment_date');
            $table->date('due_date');
            // Satu-satunya angka uang yang disimpan (ADR 2026-06-26). Breakdown
            // Pokok/Jasa/Tab, "Lain-lain", dan sisa pokok dihitung dari konstanta
            // loans.monthly_* × jumlah angsuran terbayar — bukan disimpan.
            $table->decimal('amount_paid', 18, 2);
            $table->enum('payment_method', ['potong_gaji', 'manual']);
            $table->boolean('is_reversal')->default(false);
            $table->foreignUuid('reversal_of_id')->nullable()->constrained('installments');
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
            // loan_id is already indexed by its foreign key constraint.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
