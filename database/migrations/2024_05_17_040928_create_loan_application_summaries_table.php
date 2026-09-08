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
        Schema::create('loan_application_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('borrower_id', 50);
            $table->integer('product_id');
            $table->decimal('loan_amount', 10, 2);
            $table->integer('tenure')->comment('In month');
            $table->integer('age')->nullable();
            $table->integer('cibil_score')->nullable();
            $table->bigInteger('co_applicant_id')->nullable();
            $table->bigInteger('guarantor_id')->nullable();
            $table->string('resience_type')->nullable();
            $table->decimal('residence_stability', 4, 2)->nullable();
            $table->boolean('field_verification')->default(false);
            $table->integer('dpd_30_in_3_month')->nullable()->comment('In last 3 month');
            $table->integer('dpd_90_in_1_year')->nullable()->comment('In last 1 year');
            $table->boolean('is_npa')->default(false);
            $table->boolean('is_willful_defaulter')->default(false);
            $table->boolean('is_eligible')->default(false);
            $table->longText('reason')->nullable();
            $table->bigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('borrower_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_application_summaries');
    }
};
