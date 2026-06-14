<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('loan_id')->constrained('loans');
            $table->unsignedSmallInteger('installment_seq');
            $table->date('due_date');
            $table->decimal('principal_due', 18, 2);
            $table->decimal('interest_due', 18, 2);
            $table->decimal('time_deposit_due', 18, 2);
            $table->decimal('total_due', 18, 2);
            $table->enum('status', ['Belum Bayar', 'Terbayar'])->default('Belum Bayar')->index();
            $table->timestamps();

            $table->index(['loan_id', 'installment_seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_schedules');
    }
};
