<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('member_id')->constrained('members');
            $table->decimal('amount', 18, 2);
            $table->date('transaction_date');
            $table->enum('source', ['manual', 'store_api']);
            $table->string('reference_number', 50)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_reversal')->default(false);
            $table->foreignUuid('reversal_of_id')->nullable()->constrained('shopping_transactions');
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_transactions');
    }
};
