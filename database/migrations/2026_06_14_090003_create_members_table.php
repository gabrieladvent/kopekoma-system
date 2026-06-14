<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('member_number', 20)->unique();
            $table->string('full_name', 100);
            $table->string('birth_place', 50);
            $table->date('birth_date');
            $table->enum('gender', ['L', 'P']);
            $table->string('nik', 16)->unique();
            $table->string('nip', 25)->nullable();
            $table->foreignUuid('agency_id')->constrained('agencies');
            $table->string('position', 100)->nullable();
            $table->foreignId('grade_id')->constrained('grades');
            $table->enum('employment_status', ['ASN', 'Honorer']);
            $table->string('payroll_account_number', 30);
            $table->string('bank_name', 50)->nullable();
            $table->text('address');
            $table->string('phone_number', 15);
            $table->date('join_date');
            $table->date('exit_date')->nullable();
            $table->string('heir_name', 100);
            $table->string('heir_relationship', 50);
            $table->string('heir_phone_number', 15);
            $table->enum('status', ['Aktif', 'Non-Aktif', 'Keluar', 'Meninggal'])->default('Aktif');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
