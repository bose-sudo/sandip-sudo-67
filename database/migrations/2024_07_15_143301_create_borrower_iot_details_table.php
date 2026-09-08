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
        Schema::create('borrower_iot_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('borrower_id');
            $table->string('vehicle_id', 150);
            $table->string('vehicle_uuid', 150)->nullable();
            $table->boolean('status')->default(false);
            $table->bigInteger('api_response_id');
            $table->timestamps();

            $table->index('user_id');
            $table->index('borrower_id');
            $table->index('vehicle_id');
            $table->index('api_response_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrower_iot_details');
    }
};
