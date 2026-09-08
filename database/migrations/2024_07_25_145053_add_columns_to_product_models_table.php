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
        Schema::table('product_models', function (Blueprint $table) {
            $table->decimal('interest', 4, 2)->default(0);
            $table->decimal('processing_fee', 8, 2)->default(0);
            $table->decimal('subvention_amount', 8, 2)->default(0);
            $table->decimal('fldg', 8, 2)->default(0);
            $table->decimal('iot', 8, 2)->default(0);
            $table->decimal('insurance_1st_year', 8, 2)->default(0);
            $table->decimal('insurance_2nd_year', 8, 2)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_models', function (Blueprint $table) {
            $table->dropColumn('interest');
            $table->dropColumn('processing_fee');
            $table->dropColumn('subvention_amount');
            $table->dropColumn('fldg');
            $table->dropColumn('iot');
            $table->dropColumn('insurance_1st_year');
            $table->dropColumn('insurance_2nd_year');
        });
    }
};
