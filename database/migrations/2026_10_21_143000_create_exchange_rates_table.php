<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dated rates, entered by Finance, for reporting and for allocating a
 * payment made in one currency against a balance in another. The company's
 * own currency purchases record the rate the bank really gave, elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            // How many `to` for one whole `from`: USD→MVR 15.42000000.
            $table->decimal('rate', 18, 8);
            $table->date('as_of');
            $table->string('source', 120)->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['from_currency', 'to_currency', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
