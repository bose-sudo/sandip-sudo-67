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
        Schema::create('loan_service_providers', function (Blueprint $table) {
            $table->id();
            $table->string('lsp_unq', 100)->nullable();
            $table->string('name', 255);
            $table->date('date_of_incorporation')->nullable();
            $table->string('mobile', 15)->unique();
            $table->boolean('is_mobile_verified')->default(false);
            $table->string('alternative_number', 15)->nullable();
            $table->string('email', 255)->unique();
            $table->boolean('is_email_verified')->default(false);
            $table->string('pan', 15)->unique();
            $table->boolean('is_pan_verified')->default(false);
            $table->string('aadhar', 20)->nullable();
            $table->string('landmark', 255)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->text('address_line_1')->nullable();
            $table->text('address_line_2')->nullable();
            $table->string('bank_statement', 255)->nullable();
            $table->longText('cibil_report')->nullable();
            $table->string('trade_certificate', 255)->nullable();
            $table->string('gst_certificate', 255)->nullable();
            $table->string('security_cheque_1', 255)->nullable();
            $table->string('security_cheque_2', 255)->nullable();
            $table->string('security_cheque_3', 255)->nullable();
            $table->string('security_cheque_4', 255)->nullable();
            $table->string('rent_agreement', 255)->nullable();
            $table->string('lsp_finayo_agreement', 255)->nullable();
            $table->string('office_premise_img', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('delete_date_time')->nullable();
            $table->bigInteger('modified_by')->nullable();
            $table->bigInteger('lsp_auth_id')->nullable();
            $table->timestamps();

            $table->index('lsp_unq');
            $table->index('lsp_auth_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_service_providers');
    }
};
