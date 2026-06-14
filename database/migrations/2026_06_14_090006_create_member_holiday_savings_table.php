<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_holiday_savings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('member_id')->constrained('members');
            $table->unsignedSmallInteger('period_year')->index();
            $table->decimal('monthly_amount', 18, 2);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'period_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_holiday_savings');
    }
};
