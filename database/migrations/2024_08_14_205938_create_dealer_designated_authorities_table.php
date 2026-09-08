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
        Schema::create('dealer_designated_authorities', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('dealer_id');
            $table->string('name', 255);
            $table->string('mobile', 15);
            $table->string('alternative_mobile', 15)->nullable();
            $table->string('aadhar', 15);
            $table->string('pan', 15);
            $table->string('bank_statement', 255)->nullable();
            $table->string('itr_file', 255)->nullable();
            $table->string('photo', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_designated_authorities');
    }
};
