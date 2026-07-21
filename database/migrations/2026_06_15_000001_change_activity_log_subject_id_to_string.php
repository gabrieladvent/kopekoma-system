<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subjects in this app use UUID primary keys (Agency, Member), but Spatie's
 * default `nullableMorphs('subject')` created `subject_id` as an unsigned
 * bigint. Inserting a UUID there triggers "Data truncated for column
 * subject_id" on MySQL. Widen it to a string so UUID subjects are logged.
 *
 * causer_id is left as-is: the only causer is User, which has an integer id.
 */
return new class extends Migration
{
    private function table(): string
    {
        return config('activitylog.table_name', 'activity_log');
    }

    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table($this->table(), function (Blueprint $table) {
                $table->string('subject_id')->nullable()->change();
            });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table($this->table(), function (Blueprint $table) {
                $table->unsignedBigInteger('subject_id')->nullable()->change();
            });
    }
};
