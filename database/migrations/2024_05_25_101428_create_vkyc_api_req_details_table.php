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
        Schema::create('vkyc_api_req_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('borrower_id');
            $table->string('application_number', 150);
            $table->string('mobile', 25);
            $table->longText('request');
            $table->timestamps();

            $table->index('user_id');
            $table->index('borrower_id');
            $table->index('application_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vkyc_api_req_details');
    }
};
