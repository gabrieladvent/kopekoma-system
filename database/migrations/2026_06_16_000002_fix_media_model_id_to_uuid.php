<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex('media_model_type_model_id_index');
        });

        Schema::table('media', function (Blueprint $table) {
            $table->uuid('model_id')->change();
        });

        Schema::table('media', function (Blueprint $table) {
            $table->index(['model_type', 'model_id'], 'media_model_type_model_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex('media_model_type_model_id_index');
        });

        Schema::table('media', function (Blueprint $table) {
            $table->unsignedBigInteger('model_id')->change();
        });

        Schema::table('media', function (Blueprint $table) {
            $table->index(['model_type', 'model_id'], 'media_model_type_model_id_index');
        });
    }
};
