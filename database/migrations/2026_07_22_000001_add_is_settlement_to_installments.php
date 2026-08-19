<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->boolean('is_settlement')->default(false)->after('is_reversal')->index();

            $table->unsignedSmallInteger('installment_seq')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropColumn('is_settlement');
            $table->unsignedSmallInteger('installment_seq')->nullable(false)->change();
        });
    }
};
