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
        Schema::create('vendor_service_apis', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->text('ip_address');
            $table->dateTime('date_time');
            $table->string('status', 100);
            $table->string('api_flag', 100);
            $table->longText('request');
            $table->longText('response');
            $table->timestamps();

            $table->index('user_id');
            $table->index('ip_address');
            $table->index('date_time');
            $table->index('api_flag');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_service_apis');
    }
};
