<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_clients', function (Blueprint $table) {
            $table->text('client_secret_encrypted')->nullable()->after('client_secret');
        });
    }

    public function down(): void
    {
        Schema::table('store_clients', function (Blueprint $table) {
            $table->dropColumn('client_secret_encrypted');
        });
    }
};
