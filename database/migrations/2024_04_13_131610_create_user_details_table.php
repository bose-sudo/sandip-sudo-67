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
        Schema::create('user_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->text('address')->nullable();
            $table->string('pincode', 12)->nullable();
            $table->string('city', 150)->nullable();
            $table->integer('state')->nullable();
            $table->date('dob')->nullable();
            $table->string('gender')->nullable();
            $table->string('father_name')->nullable();
            $table->integer('no_of_dependents')->nullable();
            $table->string('marital_status', 50)->nullable();
            $table->string('residence_status', 75)->nullable();
            $table->string('education', 100)->nullable();
            $table->string('occupation', 100)->nullable();
            $table->boolean('agree_for_bureau_check')->default(false);
            $table->string('reference_name')->nullable();
            $table->string('reference_number', 20)->nullable();
            $table->string('reference_relation', 20)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_details');
    }
};
