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
        Schema::create('lsp_executive_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('executive_id')->nullable();
            $table->bigInteger('lsp_id')->nullable();
            $table->date('date_of_joining')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->text('address')->nullable();
            $table->string('alternative_number', 15)->nullable();
            $table->string('pan', 15)->nullable();
            $table->string('aadhar', 20)->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lsp_executive_details');
    }
};
