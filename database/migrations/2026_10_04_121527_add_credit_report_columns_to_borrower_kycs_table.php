<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrower_kycs', function (Blueprint $table) {
            $table->unsignedBigInteger('graviton_credit_report_resource_id')
                  ->nullable()
                  ->after('graviton_loan_id');

            $table->string('graviton_credit_report_status')
                  ->nullable()
                  ->after('graviton_credit_report_resource_id');

            $table->timestamp('graviton_credit_report_synced_at')
                  ->nullable()
                  ->after('graviton_credit_report_status');

            $table->text('graviton_credit_report_error')
                  ->nullable()
                  ->after('graviton_credit_report_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('borrower_kycs', function (Blueprint $table) {
            $table->dropColumn([
                'graviton_credit_report_resource_id',
                'graviton_credit_report_status',
                'graviton_credit_report_synced_at',
                'graviton_credit_report_error',
            ]);
        });
    }
};