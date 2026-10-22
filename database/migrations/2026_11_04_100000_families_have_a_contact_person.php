<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The head of a family and the person who deals with the office are often
 * different people. A family names its leader and contact; a booking may
 * name its own, for when part of a family travels with someone else in charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('contact_pilgrim_id')->nullable()->after('head_pilgrim_id')->constrained('pilgrims')->nullOnDelete();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('contact_pilgrim_id')->nullable()->after('customer_id')->constrained('pilgrims')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropConstrainedForeignId('contact_pilgrim_id'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropConstrainedForeignId('contact_pilgrim_id'));
    }
};
