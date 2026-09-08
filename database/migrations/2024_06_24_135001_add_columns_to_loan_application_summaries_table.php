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
        Schema::table('loan_application_summaries', function (Blueprint $table) {
            $table->integer('coappli_cibil_score')->nullable();
            $table->integer('coappli_dpd_30')->nullable()->comment('In last 3 month');
            $table->integer('coappli_dpd_90')->nullable()->comment('In last 1 year');
            $table->boolean('coappli_is_npa')->default(false);
            $table->boolean('coappli_defaulter')->default(false);
            $table->integer('guarantor_cibil_score')->nullable();
            $table->integer('guarantor_dpd_30')->nullable()->comment('In last 3 month');
            $table->integer('guarantor_dpd_90')->nullable()->comment('In last 1 year');
            $table->boolean('guarantor_is_npa')->default(false);
            $table->boolean('guarantor_defaulter')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_application_summaries', function (Blueprint $table) {
            $table->dropColumn('coappli_cibil_score');
            $table->dropColumn('coappli_dpd_30');
            $table->dropColumn('coappli_dpd_90');
            $table->dropColumn('coappli_is_npa');
            $table->dropColumn('coappli_defaulter');
            $table->dropColumn('guarantor_cibil_score');
            $table->dropColumn('guarantor_dpd_30');
            $table->dropColumn('guarantor_dpd_90');
            $table->dropColumn('guarantor_is_npa');
            $table->dropColumn('guarantor_defaulter');
        });
    }
};
