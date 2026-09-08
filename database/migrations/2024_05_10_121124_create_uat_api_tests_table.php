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
        Schema::create('uat_api_tests', function (Blueprint $table) {
            $table->id();
            $table->string('api_flag', 150);
            $table->string('ip', 50);
            $table->date('date');
            $table->string('status', 100);
            $table->bigInteger('user_id')->nullable();
            $table->string('first_name', 150)->nullable();
            $table->string('middle_name', 150)->nullable();
            $table->string('last_name', 150)->nullable();
            $table->string('pan', 20)->nullable();
            $table->string('aadhar', 30)->nullable();
            $table->date('dob')->nullable();
            $table->text('address')->nullable();
            $table->text('postal')->nullable();
            $table->text('city')->nullable();
            $table->string('status_code')->nullable();
            $table->longText('request');
            $table->longText('response')->nullable();
            $table->timestamps();

            $table->index('api_flag');
            $table->index('ip');
            $table->index('date');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uat_api_tests');
    }
};
