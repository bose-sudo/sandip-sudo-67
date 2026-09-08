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
        Schema::create('lenders', function (Blueprint $table) {
            $table->id();
            $table->string('lender_unq', 100)->nullable();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('delete_date_time')->nullable();
            $table->bigInteger('modified_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lenders');
    }
};
