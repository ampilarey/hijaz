<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Umrah trip, with everything that is set up for it before a
 * single pilgrim is booked: flights, hotels, the route, prices, the extra
 * trips, a checklist and the costs. Money columns are integer minor units
 * beside a currency, always.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();      // UMR-2027-07
            $table->string('name', 120);               // July 2027 Umrah
            $table->date('departs_on');
            $table->date('returns_on');
            $table->unsignedInteger('capacity_total')->default(0);
            $table->unsignedInteger('capacity_held')->default(0);
            $table->unsignedInteger('capacity_confirmed')->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignId('leader_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'departs_on']);
        });

        Schema::create('trip_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('occupancy', 10);   // single double triple quad quint
            $table->string('pax_type', 10);    // adult child infant
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['trip_id', 'occupancy', 'pax_type']);
        });

        Schema::create('trip_flights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 12); // outbound connecting return
            $table->string('airline', 80);
            $table->string('flight_number', 20);
            $table->string('from_airport', 3);
            $table->string('to_airport', 3);
            // Local wall-clock times as printed on the ticket; never converted.
            $table->dateTime('departs_at');
            $table->dateTime('arrives_at')->nullable();
            $table->string('pnr', 50)->nullable();
            $table->unsignedInteger('seats')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('trip_hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('city', 12); // makkah madinah jeddah
            $table->string('name', 120);
            $table->string('address', 255)->nullable();
            $table->unsignedInteger('distance_to_haram_m')->nullable();
            $table->date('check_in_on');
            $table->date('check_out_on');
            $table->string('booking_reference', 60)->nullable();
            $table->string('contact_name', 80)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->json('room_types')->nullable(); // ["double","quad"]
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('trip_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('city', 12); // male jeddah makkah madinah
            $table->date('arrives_on')->nullable();
            $table->date('departs_on')->nullable();
            $table->foreignId('trip_hotel_id')->nullable()->constrained('trip_hotels')->nullOnDelete();
            $table->string('transport', 12)->nullable(); // how you leave: flight bus train
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('trip_excursions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('city', 12)->nullable();
            $table->date('scheduled_on')->nullable();
            $table->boolean('is_included')->default(false);
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('provider', 120)->nullable();
            $table->unsignedBigInteger('cost_minor')->nullable();
            $table->string('cost_currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('trip_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->date('due_on')->nullable();
            $table->boolean('is_blocking')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['trip_id', 'done_at', 'due_on']);
        });

        Schema::create('trip_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20);
            $table->string('supplier', 120)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('is_per_person')->default(false);
            $table->string('status', 12)->default('estimated'); // estimated committed paid
            $table->date('incurred_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_costs');
        Schema::dropIfExists('trip_checklist_items');
        Schema::dropIfExists('trip_excursions');
        Schema::dropIfExists('trip_stops');
        Schema::dropIfExists('trip_hotels');
        Schema::dropIfExists('trip_flights');
        Schema::dropIfExists('price_tiers');
        Schema::dropIfExists('trip_status_transitions');
        Schema::dropIfExists('trips');
    }
};
