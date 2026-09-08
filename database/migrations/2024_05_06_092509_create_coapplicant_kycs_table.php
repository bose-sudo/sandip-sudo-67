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
        Schema::create('coapplicant_kycs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('mobile', 15);
            $table->boolean('is_verified_mobile')->default(false);
            $table->string('pan', 20);
            $table->boolean('is_verified_pan')->default(false);
            $table->text('pan_message')->nullable();
            $table->string('aadhar', 20);
            $table->boolean('is_verified_aadhar')->default(false);
            $table->decimal('asset_value', 10, 2);
            $table->decimal('loan_amount', 10, 2);
            $table->decimal('down_payment', 10, 2);
            $table->date('dob_date');
            $table->string("father_name", 255);
            $table->string("relation_with_borrower", 150);
            $table->enum('gender', ['Male', 'Female', 'Others']);
            $table->string('alternate_number1', 15)->nullable();
            $table->string('alternate_number2', 15)->nullable();
            $table->string('email', 255);
            $table->integer('cibil');
            $table->integer('postal')->nullable();
            $table->string('state')->nullable();
            $table->string('country', 150)->nullable();
            $table->string('avtar', 150)->nullable();
            $table->string('is_driving_licence_verified', 150)->nullable();
            $table->string('is_electricity_verified', 150)->nullable();
            $table->string('created_by', 30)->nullable();
            $table->string('updated_by', 30)->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();

            $table->index('mobile');
            $table->index('email');
            $table->index('pan');
            $table->index('aadhar');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coapplicant_kycs');
    }
};
