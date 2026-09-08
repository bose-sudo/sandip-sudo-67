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
        Schema::create('borrower_occupations', function (Blueprint $table) {
            $table->id();
            $table->string("occupation_type", 150);
            $table->string("current_occupation", 150);
            $table->decimal("driving_experience", 4, 2);
            $table->string("vehicle_type", 150);
            $table->decimal("monthly_earning", 10, 2);
            $table->decimal("expected_monthly_earning", 10, 2);
            $table->decimal("extra_source_income", 10, 2)->nullable();
            $table->string("source_income_area", 255)->nullable();
            $table->decimal("total_income", 10, 2);
            $table->string("current_city", 255)->nullable();
            $table->string("created_by", 30)->nullable();
            $table->string("updated_by", 30)->nullable();
            $table->dateTime("deleted_at")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrower_occupations');
    }
};
