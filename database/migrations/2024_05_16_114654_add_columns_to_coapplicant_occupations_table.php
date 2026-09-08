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
        Schema::table('coapplicant_occupations', function (Blueprint $table) {
            $table->enum('resident_type', ['Owned', 'Rented'])->nullable();
            $table->text('current_address')->nullable();
            $table->decimal('years_living', 4 , 2)->default(0);
            $table->text('permanent_address')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coapplicant_occupations', function (Blueprint $table) {
            $table->dropColumn('resident_type');
            $table->dropColumn('current_address');
            $table->dropColumn('years_living');
            $table->dropColumn('permanent_address');
        });
    }
};
