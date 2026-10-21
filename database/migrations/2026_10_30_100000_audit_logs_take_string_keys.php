<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not every audited record has a numeric key: a setting is keyed by its
 * name. SQLite let the name into the integer column; MySQL refused it, so
 * saving a setting on the live site failed. The column takes any key now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('auditable_id', 128)->change();
        });
    }

    /**
     * MySQL refuses this while a non-numeric key (a setting's audit row) is
     * stored, which is the right answer: the trail is never trimmed to fit.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('auditable_id')->change();
        });
    }
};
