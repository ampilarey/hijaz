<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A chat may be waiting for one answer (the reason for a refusal); it is kept here until the next message. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->json('pending')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->dropColumn('pending');
        });
    }
};
