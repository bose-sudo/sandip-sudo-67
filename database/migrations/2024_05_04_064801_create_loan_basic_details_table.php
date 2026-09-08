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
        Schema::create('loan_basic_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('borrower_id', 30)->nullable();
            $table->text('loan_application_steps')->nullable();
            $table->string('preferred_language', 100);
            $table->string('dealer_id', 50);
            $table->string('model', 100);
            $table->decimal('loan_amount', 10, 2);
            $table->integer('loan_term');
            $table->enum('status', ['NEW', 'APPROVED', 'REJECTED', 'HOLD', 'AWAITED', 'INPROCESS', 'DISBURSED', 'CLOSED', 'INTRANSIT', 'SOFT_APPROVED', 'CREDITCHECK', 'FI', 'CAM_REPORT'])->default('NEW');
            $table->boolean('is_active')->default(false);
            $table->bigInteger('loan_details_id')->nullable();
            $table->bigInteger('borrower_details_id')->nullable();
            $table->bigInteger('co_applicant_id')->nullable();
            $table->bigInteger('guarantor_id')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('borrower_id');
            $table->index('dealer_id');
            $table->index('loan_details_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_basic_details');
    }
};
