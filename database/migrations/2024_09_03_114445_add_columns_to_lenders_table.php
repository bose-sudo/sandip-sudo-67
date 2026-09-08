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
        Schema::table('lenders', function (Blueprint $table) {
            $table->bigInteger('lender_auth_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lenders', function (Blueprint $table) {
            $table->dropColumn('lender_auth_id');
        });
    }
};
