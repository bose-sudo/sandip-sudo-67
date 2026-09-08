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
        Schema::create('lender_directors', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('lender_id');
            $table->string('name', 255);
            $table->string('email', 255);
            $table->string('mobile', 15);
            $table->bigInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lender_directors');
    }
};
