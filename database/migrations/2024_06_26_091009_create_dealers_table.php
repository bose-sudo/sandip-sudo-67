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
        Schema::create('dealers', function (Blueprint $table) {
            $table->id();
            $table->string('dealer_unq', 100)->nullable();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('delete_date_time')->nullable();
            $table->bigInteger('modified_by')->nullable();
            $table->timestamps();

            $table->index('dealer_unq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealers');
    }
};
