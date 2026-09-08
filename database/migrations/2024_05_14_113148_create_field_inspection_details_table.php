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
        Schema::create('field_inspection_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('borrower_id')->nullable();
            $table->string('name', 200);
            $table->string('mobile', 20);
            $table->boolean('is_inspection_verified')->default(false);
            $table->dateTime('verification_time')->nullable();
            $table->timestamps();

            $table->index('borrower_id');
            $table->index('mobile');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_inspection_details');
    }
};
