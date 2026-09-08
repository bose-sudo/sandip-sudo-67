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
        Schema::table('loan_basic_details', function (Blueprint $table) {
            $table->boolean('emi_debit_consent')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_basic_details', function (Blueprint $table) {
            $table->dropColumn('emi_debit_consent');
        });
    }
};
