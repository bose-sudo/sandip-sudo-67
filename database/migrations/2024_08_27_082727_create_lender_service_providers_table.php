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
        Schema::create('lender_service_providers', function (Blueprint $table) {
            $table->id();
            $table->string('lsp_unq', 100)->nullable();
            $table->string('name', 255);
            $table->string('mobile', 15);
            $table->boolean('is_mobile_verified')->default(false);
            $table->string('alternative_number', 15)->nullable();
            $table->string('email', 255);
            $table->boolean('is_email_verified')->default(false);
            $table->string('pan', 15)->nullable();
            $table->boolean('is_pan_verified')->default(false);
            $table->string('aadhar', 20)->nullable();
            $table->string('landmark', 255)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->text('address_line_1')->nullable();
            $table->text('address_line_2')->nullable();
            $table->string('company_type', 100);
            $table->string('gst', 25)->nullable();
            $table->string('gst_certificate', 255)->nullable();
            $table->string('lender_lsp_agreement', 255)->nullable();
            $table->string('finayo_lsp_agreement', 255)->nullable();
            $table->string('moa_and_aoa', 250)->nullable();
            $table->string('certificate_of_incorporation', 250)->nullable();
            $table->string('address_proof_doc', 250)->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->bigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index('lsp_unq');
            $table->index('pan');
            $table->index('mobile');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lender_service_providers');
    }
};
