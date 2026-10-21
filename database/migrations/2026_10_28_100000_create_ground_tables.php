<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On the ground: head counts at every move, incidents as they happen, and
 * the one-time links that let a customer see their own booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roll_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('moment', 80); // "Boarding at Jeddah airport"
            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('taken_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'taken_at']);
        });

        Schema::create('roll_call_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roll_call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_pilgrim_id')->constrained()->cascadeOnDelete();
            $table->string('state', 8); // present absent excused
            $table->string('note', 255)->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at');
            $table->timestamps();

            $table->unique(['roll_call_id', 'booking_pilgrim_id']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('client_uuid', 36)->nullable()->unique(); // made on the phone, so a retry is not a second incident
            $table->string('reference', 20)->nullable()->unique();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_pilgrim_id')->nullable()->constrained()->nullOnDelete();
            $table->string('severity', 10); // minor serious emergency
            $table->string('category', 20);
            $table->string('summary', 255);
            $table->text('detail')->nullable();
            $table->string('location', 255)->nullable();
            $table->timestamp('happened_at');
            $table->string('status', 10)->default('open');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'status']);
        });

        Schema::create('portal_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_accesses');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('roll_call_marks');
        Schema::dropIfExists('roll_calls');
    }
};
