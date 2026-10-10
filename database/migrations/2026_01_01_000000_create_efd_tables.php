<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One profile per owner (e.g. a show / shop / tenant in the host app).
        Schema::create('efd_profiles', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');

            // Entered by the owner in the settings screen.
            $table->string('tin');
            $table->string('cert_key');                       // EFD serial, e.g. 10TXXXXX73
            $table->longText('certificate')->nullable();      // base64 .pfx, encrypted
            $table->text('certificate_password')->nullable(); // encrypted
            $table->string('certificate_serial')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();

            // Returned by TRA on registration.
            $table->text('username')->nullable();             // encrypted
            $table->text('password')->nullable();             // encrypted
            $table->string('regid')->nullable();
            $table->string('efd_serial')->nullable();
            $table->string('uin')->nullable();
            $table->string('vrn')->nullable();
            $table->string('name')->nullable();
            $table->string('mobile')->nullable();
            $table->string('address')->nullable();
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('region')->nullable();
            $table->string('tax_office')->nullable();
            $table->string('receipt_code')->nullable();       // RCTVNUM
            $table->string('routing_key')->nullable();
            $table->string('token_path')->nullable();
            $table->json('tax_rates')->nullable();
            $table->json('registration_info')->nullable();    // raw response minus credentials
            $table->timestamp('registered_at')->nullable();

            // Counters & running totals.
            $table->unsignedBigInteger('gc')->default(0);     // global counter
            $table->unsignedInteger('dc')->default(0);        // daily counter
            $table->date('dc_date')->nullable();
            $table->decimal('gross', 18, 2)->default(0);      // cumulative sales across Z-reports

            // Cached bearer token.
            $table->text('access_token')->nullable();         // encrypted
            $table->timestamp('token_expires_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });

        Schema::create('efd_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('efd_profile_id')->constrained('efd_profiles')->cascadeOnDelete();
            $table->nullableMorphs('receivable');             // the host app's sale / invoice

            $table->string('rct_date', 10);                   // Y-m-d (TRA local time)
            $table->string('rct_time', 8);                    // H:i:s
            $table->unsignedBigInteger('rctnum');
            $table->unsignedBigInteger('gc');
            $table->unsignedInteger('dc');
            $table->string('znum', 8);                        // Ymd
            $table->string('rctvnum')->nullable();

            $table->unsignedTinyInteger('customer_id_type')->default(6);
            $table->string('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();

            $table->json('items');
            $table->decimal('total_excl', 18, 2);
            $table->decimal('total_incl', 18, 2);
            $table->json('vat_totals');                       // {"A":{"net":"..","tax":".."},...}
            $table->string('payment_type', 16);

            $table->string('status', 16)->default('pending')->index(); // pending|posted|failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('ack_code')->nullable();
            $table->string('ack_message')->nullable();
            $table->longText('request_xml')->nullable();
            $table->longText('response_xml')->nullable();
            $table->string('verification_url')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['efd_profile_id', 'gc']);
            $table->index(['efd_profile_id', 'znum', 'status']);
        });

        Schema::create('efd_z_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('efd_profile_id')->constrained('efd_profiles')->cascadeOnDelete();
            $table->string('znum', 8);
            $table->date('report_date');
            $table->decimal('daily_total', 18, 2)->default(0);
            $table->decimal('gross', 18, 2)->default(0);
            $table->unsignedInteger('tickets_fiscal')->default(0);
            $table->json('summary')->nullable();
            $table->string('status', 16)->default('pending'); // pending|posted|failed
            $table->string('ack_code')->nullable();
            $table->string('ack_message')->nullable();
            $table->longText('request_xml')->nullable();
            $table->longText('response_xml')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['efd_profile_id', 'znum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efd_z_reports');
        Schema::dropIfExists('efd_receipts');
        Schema::dropIfExists('efd_profiles');
    }
};
