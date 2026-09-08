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
        Schema::table('lender_charges', function (Blueprint $table) {
            $table->decimal('finayo_share', 10, 2)->default(0);
            $table->decimal('nbfc_share', 10, 2)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lender_charges', function (Blueprint $table) {
            $table->dropColumn('finayo_share');
            $table->dropColumn('nbfc_share');
        });
    }
};
