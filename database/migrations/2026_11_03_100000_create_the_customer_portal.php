<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer portal: a customer signs in with their mobile number and a
 * password they set from the office's link, and signs the booking's
 * agreement on screen or sends a signed scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('portal_password')->nullable()->after('notes');
            $table->timestamp('portal_password_set_at')->nullable()->after('portal_password');
            $table->timestamp('portal_last_login_at')->nullable()->after('portal_password_set_at');
        });

        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            // The wording as signed: later edits in Settings never change a signed agreement.
            $table->longText('terms');
            $table->char('terms_hash', 64);
            $table->string('method', 10)->nullable(); // digital scan
            $table->string('signed_name', 160)->nullable();
            $table->longText('signature')->nullable(); // encrypted PNG data URI, drawn on screen
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); // staff who uploaded a scan
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreements');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['portal_password', 'portal_password_set_at', 'portal_last_login_at']);
        });
    }
};
