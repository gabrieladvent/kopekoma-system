<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NIK memakai unique index polos yang IKUT menghitung baris ter–soft-delete,
 * sehingga membuat anggota baru dengan NIK milik anggota yang sudah dihapus
 * (soft delete) selalu gagal di level DB — walau validasi aplikasi memakai
 * Rule::unique()->withoutTrashed(). Keunikan NIK "aktif" tetap dijaga di lapisan
 * validasi (MemberForm & import Excel). Index diganti index non-unik agar
 * pencarian per-NIK tetap cepat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique('members_nik_unique');
            $table->index('nik');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['nik']);
            $table->unique('nik');
        });
    }
};
