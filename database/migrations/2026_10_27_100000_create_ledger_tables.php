<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's books: every rufiyaa, dollar and riyal in or out of an
 * account, categorised, against a trip or as overhead, with the evidence.
 * Entries are never edited or deleted; a wrong one is voided and re-entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_categories', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8); // income expense
            $table->string('slug', 40)->unique();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('direction', 3); // in out
            $table->string('kind', 10); // income expense transfer exchange
            $table->foreignId('category_id')->nullable()->constrained('ledger_categories')->nullOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->date('occurred_on');
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('mvr_minor')->nullable();  // at the dated table rate, for reporting
            $table->decimal('rate_snapshot', 18, 8)->nullable();   // the rate used for mvr_minor, or the realised rate of an exchange
            $table->string('description', 255);
            $table->string('counterparty', 160)->nullable();      // supplier, beneficiary, payer
            $table->string('reference', 80)->nullable();          // bank or TT reference
            $table->string('group_key', 36)->nullable();          // the entries of one transfer or one exchange
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->json('meta')->nullable();                     // beneficiary bank, charges, anything the kind needs
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'occurred_on']);
            $table->index(['trip_id', 'category_id']);
            $table->index(['occurred_on', 'kind']);
            $table->index('group_key');
            $table->unique(['payment_id', 'direction']);
        });

        Schema::create('usd_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_pilgrim_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor'); // USD
            $table->string('note', 160)->nullable();
            $table->timestamps();

            $table->unique(['trip_id', 'booking_pilgrim_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usd_allocations');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_categories');
    }
};
