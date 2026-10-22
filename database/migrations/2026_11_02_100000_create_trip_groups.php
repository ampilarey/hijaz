<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A group is the people who travel on one flight. A trip may have several,
 * flying on different days; each group has its own buses (about 49 a bus),
 * and each traveller is in one group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->foreignId('outbound_flight_id')->nullable()->constrained('trip_flights')->nullOnDelete();
            $table->foreignId('return_flight_id')->nullable()->constrained('trip_flights')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('bus_groups', function (Blueprint $table) {
            $table->foreignId('trip_group_id')->nullable()->after('trip_id')->constrained('trip_groups')->nullOnDelete();
        });

        Schema::table('booking_pilgrims', function (Blueprint $table) {
            $table->foreignId('trip_group_id')->nullable()->after('bus_group_id')->constrained('trip_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('booking_pilgrims', fn (Blueprint $table) => $table->dropConstrainedForeignId('trip_group_id'));
        Schema::table('bus_groups', fn (Blueprint $table) => $table->dropConstrainedForeignId('trip_group_id'));
        Schema::dropIfExists('trip_groups');
    }
};
