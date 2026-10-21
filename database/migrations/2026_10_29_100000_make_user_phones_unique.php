<?php

use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A mobile number signs someone in, so it is kept in one shape and belongs to one account. */
return new class extends Migration
{
    public function up(): void
    {
        $seen = [];

        foreach (DB::table('users')->whereNotNull('phone')->orderBy('id')->get(['id', 'phone']) as $user) {
            $normalised = Phone::normalise($user->phone);
            $value = $normalised !== null && ! isset($seen[$normalised]) ? $normalised : null;

            if ($value !== null) {
                $seen[$value] = true;
            }

            DB::table('users')->where('id', $user->id)->update(['phone' => $value]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
    }
};
