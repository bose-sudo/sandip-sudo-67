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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('borrower_id');
            $table->longText('aadhar_front')->nullable();
            $table->longText('aahar_back')->nullable();
            $table->longText('pan_doc')->nullable();
            $table->string('driving_license', 255)->nullable();
            $table->string('per_address_proof', 255)->nullable();
            $table->string('res_address_proof', 255)->nullable();
            $table->string('vehicle_quotation', 255)->nullable();
            $table->longText('coAadhar_front')->nullable();
            $table->longText('coAahar_back')->nullable();
            $table->longText('coPan_doc')->nullable();
            $table->string('coDriving_license', 255)->nullable();
            $table->string('coPerAddress_proof', 255)->nullable();
            $table->string('coResAddress_proof', 255)->nullable();
            $table->string('additional_doc', 255)->nullable();
            $table->string('invoice', 255)->nullable();
            $table->string('performa', 255)->nullable();
            $table->string('down_payment_receipt', 255)->nullable();
            $table->string('insurance', 255)->nullable();
            $table->string('rto_tax_receipt', 255)->nullable();
            $table->string('dl_receipt', 255)->nullable();
            $table->string('documents_form', 255)->nullable();
            $table->integer('created_by')->nullable();;
            $table->integer('updated_by')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->longText('guarantor_aadhar_front')->nullable();
            $table->longText('guarantor_aadhar_back')->nullable();
            $table->string('guarantor_driving_license', 255)->nullable();
            $table->longText('guarantor_pan_doc')->nullable();
            $table->string('guarantor_peraddress_proof', 255)->nullable();
            $table->string('guarantor_coresaddress_proof', 255)->nullable();
            $table->string('borrower_avtar', 255)->nullable();
            $table->string('coapplicant_avtar', 255)->nullable();
            $table->string('guarantor_avtar', 255)->nullable();
            $table->string('loan_file', 255)->nullable();
            $table->string('battery_guaranty', 255)->nullable();
            $table->string('warrant_document', 255)->nullable();
            $table->timestamps();

            $table->index('borrower_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
