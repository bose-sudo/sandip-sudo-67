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
        Schema::create('borrower_reference_details', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('borrower_id')->nullable();
            $table->string('reference_1', 200);
            $table->string('mobile_1', 20);
            $table->string('relation_1', 75);
            $table->text('address_1');
            $table->boolean('is_verified_mobile_1')->default(false);
            $table->string('reference_2', 200);
            $table->string('mobile_2', 20);
            $table->string('relation_2', 75);
            $table->text('address_2');
            $table->boolean('is_verified_mobile_2')->default(false);
            $table->timestamps();

            $table->index('borrower_id');
            $table->index('mobile_1');
            $table->index('mobile_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrower_reference_details');
    }
};
