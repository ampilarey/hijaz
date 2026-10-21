<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every place Hijaz's money sits: a bank account per currency, the office
 * cash boxes, and a group leader's riyal float during a trip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('kind', 20); // bank, cash, leader_cash
            $table->string('currency', 3);
            $table->string('bank', 80)->nullable();
            $table->string('account_name', 120)->nullable();
            $table->text('account_number')->nullable(); // encrypted
            $table->foreignId('holder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_invoices')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
