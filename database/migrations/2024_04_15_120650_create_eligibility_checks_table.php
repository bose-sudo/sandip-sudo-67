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
        Schema::create('eligibility_checks', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('language', 75);
            $table->string('partner_code', 50);
            $table->string('service', 50);
            $table->string('sub_service', 50);
            $table->date('dob');
            $table->decimal('monthly_income', 10, 2)->default(0.00);
            $table->string('occupation', 100);
            $table->decimal('loan_amount', 10, 2)->default(0.00);
            $table->integer('loan_term');
            $table->string('loan_purpose', 300);
            $table->boolean('is_eligible')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index('user_id');
            $table->index('partner_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eligibility_checks');
    }
};
