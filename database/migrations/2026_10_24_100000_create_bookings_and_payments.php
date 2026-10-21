<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A booking is a customer's seats on a trip; its lines are the money, each
 * in its own currency, so a booking keeps a balance per currency. Payments
 * are polymorphic (a booking now, an excursion booking too) and only ever
 * appended; a refund is a negative payment. Paid totals are re-derived
 * from allocations under a row lock, never incremented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique(); // HJZ-B-2027-0001
            $table->foreignId('trip_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('status', 12)->default('draft'); // draft confirmed cancelled completed
            $table->unsignedInteger('seats')->default(0);
            $table->json('totals')->nullable();  // {"MVR": 2850000, "USD": 30000}
            $table->json('paid')->nullable();    // same shape, re-derived
            $table->json('trip_snapshot')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trip_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('booking_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('seat_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('seats');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('released_reason', 12)->nullable(); // expired confirmed cancelled
            $table->timestamps();

            $table->index(['trip_id', 'released_at', 'expires_at']);
        });

        Schema::create('booking_pilgrims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pilgrim_id')->constrained()->restrictOnDelete();
            $table->string('occupancy', 10);
            $table->string('pax_type', 10)->default('adult');
            $table->foreignId('price_tier_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('currency', 3)->default('MVR');
            $table->boolean('is_lead')->default(false);
            $table->string('ticket_number', 30)->nullable();
            $table->string('visa_status', 12)->default('not_started'); // not_started submitted issued rejected
            $table->timestamps();

            $table->unique(['booking_id', 'pilgrim_id']);
        });

        Schema::create('booking_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_pilgrim_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 14); // seat room_upgrade extra discount fee
            $table->string('description', 160);
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_amount_minor');
            $table->bigInteger('amount_minor'); // signed; a discount is negative
            $table->string('currency', 3);
            $table->foreignId('trip_excursion_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 8); // fixed percent
            $table->decimal('value', 12, 2);
            $table->string('currency', 3);
            $table->bigInteger('amount_minor'); // what it came to, negative
            $table->string('reason', 255);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60);
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->date('due_on');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('excursion_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique(); // HJZ-X-2027-0001
            $table->foreignId('trip_excursion_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3)->default('SAR');
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->string('status', 12)->default('open'); // open paid cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('excursion_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('excursion_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_pilgrim_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->unique(['excursion_booking_id', 'booking_pilgrim_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique(); // HJZ-P-2027-0001
            $table->morphs('payable');
            $table->foreignId('refund_of_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('method', 14); // bank_transfer cash
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3);
            $table->bigInteger('amount_minor'); // signed; a refund is negative
            $table->string('status', 16)->default('pending'); // pending awaiting_review succeeded failed cancelled
            $table->date('paid_on')->nullable();
            $table->string('payer_name', 120)->nullable();
            $table->string('payer_bank', 60)->nullable();
            $table->string('payer_reference', 80)->nullable();
            $table->string('channel', 20)->default('portal');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('payer_reference');
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // created slip_uploaded reviewed allocated
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3);          // the balance it settles
            $table->bigInteger('amount_minor');      // in that currency, signed
            $table->decimal('rate_snapshot', 18, 8)->nullable(); // payment currency -> this currency
            $table->foreignId('exchange_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payment_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('excursion_participants');
        Schema::dropIfExists('excursion_bookings');
        Schema::dropIfExists('payment_schedules');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('booking_lines');
        Schema::dropIfExists('booking_pilgrims');
        Schema::dropIfExists('seat_holds');
        Schema::dropIfExists('booking_status_transitions');
        Schema::dropIfExists('bookings');
    }
};
