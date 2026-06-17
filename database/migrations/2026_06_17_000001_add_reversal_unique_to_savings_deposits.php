<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_deposits', function (Blueprint $table) {
            $table->unique('reversal_of_id', 'savings_deposits_reversal_of_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('savings_deposits', function (Blueprint $table) {
            $table->index('reversal_of_id', 'savings_deposits_reversal_of_id_index');
            $table->dropUnique('savings_deposits_reversal_of_id_unique');
        });
    }
};
