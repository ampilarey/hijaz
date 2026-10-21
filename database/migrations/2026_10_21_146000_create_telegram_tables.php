<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A staff account bound to a Telegram chat. The bot acts as this user.
        Schema::create('telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('chat_id');
            $table->string('telegram_username', 64)->nullable();
            $table->string('telegram_name', 128)->nullable();
            $table->timestamp('linked_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'revoked_at']);
            $table->index(['user_id', 'revoked_at']);
        });

        // One-time codes shown in the portal and sent to the bot as /start CODE.
        Schema::create('telegram_link_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        // Every update Telegram delivered, keyed on its id, so a retried
        // delivery is answered once.
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->string('kind', 30)->nullable();
            $table->string('command', 40)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('handled_at')->nullable();
            $table->string('error', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('telegram_link_codes');
        Schema::dropIfExists('telegram_links');
    }
};
