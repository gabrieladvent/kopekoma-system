<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie's media table is created with `morphs('model')`, which makes
 * `model_id` an unsigned BIGINT. Our media-owning model (Member) uses a UUID
 * primary key, so a UUID gets truncated on insert ("Data truncated for column
 * 'model_id'"). Convert the morph key to a UUID-compatible string column.
 *
 * A string column still works for any future integer-keyed media owner (MySQL
 * compares the value as a string), so this is safe across models.
 */
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
