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
        Schema::create('lender_dealer_product_charges', function (Blueprint $table) {
            $table->id();
            $table->string('token_id', 100)->nullable();
            $table->bigInteger('lender_id');
            $table->bigInteger('dealer_id');
            $table->string('charge_head', 255);
            $table->string('charge_type', 100);
            $table->integer('charge_value')->nullable();
            $table->string('status', 50)->default('ACTIVE');
            $table->string('ip', 50);
            $table->bigInteger('user_id');
            $table->timestamps();

            $table->index('token_id');
            $table->index('lender_id');
            $table->index('dealer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lender_dealer_product_charges');
    }
};
