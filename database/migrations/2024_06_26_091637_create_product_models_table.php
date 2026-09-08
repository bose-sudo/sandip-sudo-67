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
        Schema::create('product_models', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('dealer_id');
            $table->string('model_unq', 100);
            $table->string('name', 255);
            $table->string('battery_type', 100);
            $table->decimal('ex_showroom_price', 8, 2)->nullable();
            $table->string('loan_to_value_ratio', 100);
            $table->string('manufacture_name', 255);
            $table->decimal('min_loan_amount', 8, 2);
            $table->decimal('max_loan_amount', 8, 2);
            $table->integer('min_loan_tenure');
            $table->integer('max_loan_tenure');
            $table->timestamps();

            $table->index('dealer_id');
            $table->index('model_unq');
            $table->index('battery_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_models');
    }
};
