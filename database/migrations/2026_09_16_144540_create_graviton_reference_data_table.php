<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graviton_reference_data', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('borrower_id')->index();

            // JSON responses
            $table->json('loan_template_data')->nullable();
            $table->json('family_member_template_data')->nullable();
            $table->json('credit_bureau_template_data')->nullable();

            // Extracted values
            $table->string('repayment_every')->nullable();
            $table->string('loan_term_frequency')->nullable();
            $table->string('loan_term_frequency_type')->nullable();       // ✅ NEW
            $table->string('transaction_processing_strategy_id')->nullable();
            $table->string('loan_purpose_id')->nullable();                // ✅ NEW
            $table->string('legal_form_id')->nullable();
            $table->string('salutation_id')->nullable();
            $table->string('document_type_id')->nullable();

            // Sync status
            $table->enum('sync_status', ['pending', 'success', 'failed'])->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->foreign('borrower_id')
                  ->references('id')
                  ->on('borrower_kycs')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graviton_reference_data');
    }
};