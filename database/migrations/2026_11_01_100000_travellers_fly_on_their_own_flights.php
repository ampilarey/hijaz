<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trip may fly out and back on several flights, on different days, so each
 * traveller is put on their own. Left empty, a traveller is on the trip's
 * only flight in that direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_pilgrims', function (Blueprint $table) {
            $table->foreignId('outbound_flight_id')->nullable()->after('bus_group_id')->constrained('trip_flights')->nullOnDelete();
            $table->foreignId('return_flight_id')->nullable()->after('outbound_flight_id')->constrained('trip_flights')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('booking_pilgrims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_flight_id');
            $table->dropConstrainedForeignId('outbound_flight_id');
        });
    }
};
