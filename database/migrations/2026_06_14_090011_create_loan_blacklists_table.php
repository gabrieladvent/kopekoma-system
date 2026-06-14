<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_blacklists', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('member_id')->constrained('members');
            $table->text('reason');
            $table->boolean('is_active')->default(true);
            $table->date('blacklisted_at');
            $table->date('released_at')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['member_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_blacklists');
    }
};
