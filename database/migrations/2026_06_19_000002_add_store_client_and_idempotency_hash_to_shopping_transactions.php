<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_transactions', function (Blueprint $table) {
            $table->foreignUuid('store_client_id')->nullable()->after('source')->constrained('store_clients');
            $table->string('idempotency_hash', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('shopping_transactions', function (Blueprint $table) {
            $table->dropForeign(['store_client_id']);
            $table->dropColumn(['store_client_id', 'idempotency_hash']);
        });
    }
};
