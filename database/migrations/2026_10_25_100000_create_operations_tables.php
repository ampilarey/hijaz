<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Getting a trip on the road: bus groups, rooms per hotel stay, the cards
 * and luggage tags pilgrims carry, and the per-trip facts about each
 * traveller (bus, visa, ticket, check-in) that live on the booking pilgrim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->foreignId('leader_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('leader_name', 80)->nullable();
            $table->string('leader_phone', 40)->nullable();
            $table->string('colour', 7)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['trip_id', 'name']);
        });

        Schema::table('booking_pilgrims', function (Blueprint $table) {
            $table->foreignId('bus_group_id')->nullable()->after('is_lead')->constrained()->nullOnDelete();
            $table->timestamp('checked_in_at')->nullable()->after('bus_group_id');
            $table->string('seat_number', 8)->nullable()->after('ticket_number');
            $table->string('visa_number', 40)->nullable()->after('visa_status');
            $table->string('visa_agent', 80)->nullable()->after('visa_number');
            $table->date('visa_submitted_on')->nullable()->after('visa_agent');
            $table->date('visa_issued_on')->nullable()->after('visa_submitted_on');
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_hotel_id')->constrained()->cascadeOnDelete();
            $table->string('label', 20);
            $table->unsignedSmallInteger('capacity');
            $table->string('gender', 8)->nullable(); // male female family
            $table->string('notes', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['trip_hotel_id', 'label']);
        });

        Schema::create('room_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_pilgrim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['room_id', 'booking_pilgrim_id']);
        });

        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_pilgrim_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // customer luggage
            $table->string('serial', 16)->unique();
            $table->string('qr_token', 64)->unique();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->unsignedInteger('printed_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['booking_pilgrim_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
        Schema::dropIfExists('room_assignments');
        Schema::dropIfExists('rooms');

        Schema::table('booking_pilgrims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bus_group_id');
            $table->dropColumn(['checked_in_at', 'seat_number', 'visa_number', 'visa_agent', 'visa_submitted_on', 'visa_issued_on']);
        });

        Schema::dropIfExists('bus_groups');
    }
};
