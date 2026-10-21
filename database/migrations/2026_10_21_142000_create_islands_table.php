<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The inhabited islands by atoll, so an address can resolve to one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('islands', function (Blueprint $table) {
            $table->id();
            $table->string('atoll_code', 8);
            $table->string('atoll_name', 60);
            $table->string('name', 80);
            $table->string('name_dv', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['atoll_code', 'name']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('islands');
    }
};
