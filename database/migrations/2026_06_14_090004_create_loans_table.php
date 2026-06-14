<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('loan_number', 25)->unique();
            $table->foreignUuid('member_id')->constrained('members');
            $table->enum('loan_type', ['jangka_pendek', 'jangka_panjang'])->index();
            $table->decimal('principal_amount', 18, 2);
            $table->decimal('admin_fee', 18, 2)->default(0);
            $table->decimal('swp_amount', 18, 2)->default(0);
            $table->decimal('disbursed_amount', 18, 2);
            $table->unsignedSmallInteger('term_months');
            $table->date('disbursement_date');
            $table->date('first_due_date')->nullable();
            $table->enum('status', ['Cair', 'Lunas'])->default('Cair')->index();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
