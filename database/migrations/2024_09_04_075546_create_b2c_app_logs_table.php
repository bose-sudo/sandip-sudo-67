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
        Schema::create('b2c_app_logs', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('api_description', 255);
            $table->longText('input');
            $table->string('ip', 20);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('b2c_app_logs');
    }
};
