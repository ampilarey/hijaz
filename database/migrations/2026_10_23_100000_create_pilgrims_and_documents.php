<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people who travel and the papers that prove who they are.
 *
 * A customer is the paying party, an individual or a family. A pilgrim is
 * one human, reused across years. Identifiers are encrypted; beside each is
 * a keyed hash so the same number can be found again without the database
 * ever holding it in the clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 12)->default('individual'); // individual family
            $table->string('name', 120);
            $table->unsignedBigInteger('head_pilgrim_id')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('phone_alt', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->foreignId('island_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 40)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('phone');
            $table->index('name');
        });

        Schema::create('pilgrims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('relationship_to_head', 12)->default('self');
            $table->string('name_en', 120);
            $table->string('name_dv', 120)->nullable();
            $table->string('gender', 8)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('id_number')->nullable();          // encrypted
            $table->string('id_number_hash', 64)->nullable();
            $table->date('id_expires_on')->nullable();
            $table->string('address', 255)->nullable();
            $table->foreignId('island_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nationality', 3)->default('MDV');
            $table->text('passport_number')->nullable();    // encrypted
            $table->string('passport_number_hash', 64)->nullable();
            $table->date('passport_issued_on')->nullable();
            $table->date('passport_expires_on')->nullable();
            $table->string('passport_issuing_country', 3)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->text('medical_notes')->nullable();
            $table->json('needs')->nullable();
            $table->string('registration_status', 12)->default('registered'); // registered verified
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->json('identity_mismatches')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('name_en');
            $table->index('id_number_hash');
            $table->index('passport_number_hash');
            $table->index('passport_expires_on');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreign('head_pilgrim_id')->references('id')->on('pilgrims')->nullOnDelete();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');                 // pilgrim now, payment later
            $table->string('type', 20);                     // id_card passport photo visa vaccination other
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete(); // a visa is for a trip
            $table->string('status', 12)->default('pending'); // pending verified rejected
            $table->string('number', 60)->nullable();       // visa number, certificate number
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['documentable_type', 'documentable_id', 'type']);
            $table->index(['status', 'created_at']);
            $table->index('expires_at');
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20)->default('portal');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'version']);
            $table->index('checksum');
        });

        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->string('reader', 20);                   // tesseract anthropic manual
            $table->string('model', 80)->nullable();
            $table->json('fields')->nullable();             // proposed values
            $table->json('confidence')->nullable();         // 0..1 per field
            $table->longText('raw_text')->nullable();
            $table->boolean('mrz_valid')->nullable();
            $table->string('status', 12)->default('proposed'); // proposed accepted rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('accepted_fields')->nullable();    // what staff actually saved
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('document_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->string('status', 12)->default('review'); // review confirmed discarded
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('document_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_batch_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 120);
            $table->unsignedInteger('page')->nullable();
            $table->json('fields')->nullable();
            $table->foreignId('matched_pilgrim_id')->nullable()->constrained('pilgrims')->nullOnDelete();
            $table->string('match_reason', 20)->nullable(); // passport_number name manual
            $table->string('status', 12)->default('unmatched'); // matched unmatched assigned attached discarded
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_batch_items');
        Schema::dropIfExists('document_batches');
        Schema::dropIfExists('document_extractions');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['head_pilgrim_id']);
        });
        Schema::dropIfExists('pilgrims');
        Schema::dropIfExists('customers');
    }
};
