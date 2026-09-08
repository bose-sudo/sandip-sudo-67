<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('lenders', function (Blueprint $table) {
            $table->string('agreement_doc', 255)->nullable();
            $table->string('company_type', 100)->nullable();
            $table->string('coi_doc', 255)->nullable();
            $table->string('nbfc_certificate', 255)->nullable();
            $table->string('complience_officer_name', 255)->nullable();
            $table->string('complience_officer_mobile', 15)->nullable();
            $table->string('complience_officer_email', 255)->nullable();
            $table->string('processing_fee_sharing_status', 50)->default('NO');
            $table->decimal('finayo_share', 10, 2)->nullable();
            $table->decimal('lender_share', 10, 2)->nullable();
            $table->decimal('processing_fee_amount', 10, 2)->default(0);
            $table->decimal('lending_roi', 10, 2)->default(0);
            $table->bigInteger('user_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lenders', function (Blueprint $table) {
            $table->dropColumn('agreement_doc');
            $table->dropColumn('company_type');
            $table->dropColumn('coi_doc');
            $table->dropColumn('nbfc_certificate');
            $table->dropColumn('complience_officer_name');
            $table->dropColumn('complience_officer_mobile');
            $table->dropColumn('complience_officer_email');
            $table->dropColumn('processing_fee_sharing_status');
            $table->dropColumn('finayo_share');
            $table->dropColumn('lender_share');
            $table->dropColumn('processing_fee_amount');
            $table->dropColumn('lending_roi');
            $table->dropColumn('user_id');
        });
    }
};
