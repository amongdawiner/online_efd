<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efd_receipts', function (Blueprint $table) {
            // Test receipts are sent to TRA like any other, but kept out of Z-reports.
            $table->boolean('is_test')->default(false)->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('efd_receipts', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
