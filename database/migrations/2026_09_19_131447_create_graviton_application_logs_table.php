<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graviton_application_logs', function (Blueprint $table) {
            $table->id();

            // Foreign key
            $table->unsignedBigInteger('borrower_id')->index();

            // Request tracking
            $table->string('endpoint')->nullable();              // /loans/newApplicationForm
            $table->json('request_payload')->nullable();         // Sent payload
            $table->json('response_payload')->nullable();        // Graviton response
            $table->integer('http_status')->nullable();          // 200/400/500

            // Graviton IDs
            $table->unsignedBigInteger('graviton_client_id')->nullable();
            $table->unsignedBigInteger('graviton_loan_id')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();

            // Status tracking
            $table->enum('status', ['pending', 'success', 'failed', 'skipped'])
                  ->default('pending');
            $table->text('error_message')->nullable();
            $table->json('validation_errors')->nullable();

            // Timing
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->foreign('borrower_id')
                  ->references('id')
                  ->on('borrower_kycs')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graviton_application_logs');
    }
};