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
        Schema::create('dealer_company_documents', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('dealer_id');
            $table->string('company_type', 100);
            $table->string('pan', 50)->nullable();
            $table->string('aadhar', 50)->nullable();
            $table->string('itr_file', 250)->nullable();
            $table->string('bank_statement', 250)->nullable();
            $table->string('icat_certificate', 250)->nullable();
            $table->string('rent_or_lease_agreement', 250)->nullable();
            $table->string('utility_bill', 250)->nullable();
            $table->string('manufacturing_license', 250)->nullable();
            $table->string('partnership_deed', 250)->nullable();
            $table->string('balance_sheet', 250)->nullable();
            $table->string('moa_and_aoa', 250)->nullable();
            $table->string('certificate_of_incorporation', 250)->nullable();
            $table->string('factory_address_proof', 250)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_company_documents');
    }
};
