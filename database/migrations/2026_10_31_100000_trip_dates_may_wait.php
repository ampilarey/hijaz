<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trip is often planned before its dates are fixed, so a draft may have
 * none. It cannot open for registration, or take a booking, until both are set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->date('departs_on')->nullable()->change();
            $table->date('returns_on')->nullable()->change();
        });

        $this->restoreTheCapacityRule();
    }

    /** Refused while any trip still has no dates, which is right: nothing is guessed for it. */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->date('departs_on')->nullable(false)->change();
            $table->date('returns_on')->nullable(false)->change();
        });

        $this->restoreTheCapacityRule();
    }

    /**
     * SQLite changes a column by rebuilding the table, which drops the
     * triggers that stop overbooking; they are made again. MySQL alters in
     * place and keeps its CHECK.
     */
    private function restoreTheCapacityRule(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            (require __DIR__.'/2026_10_22_100100_add_the_trip_capacity_constraint.php')->up();
        }
    }
};
