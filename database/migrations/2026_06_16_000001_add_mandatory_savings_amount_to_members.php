<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Snapshot of the mandatory monthly savings for this member (D1).
            // Defaults from the member's grade but is stored per-member so a
            // later grade change never rewrites historical reconciliation data.
            $table->decimal('mandatory_savings_amount', 18, 2)->nullable()->after('grade_id');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('mandatory_savings_amount');
        });
    }
};
