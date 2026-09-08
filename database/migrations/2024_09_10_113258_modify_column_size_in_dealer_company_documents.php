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
        Schema::table('dealer_company_documents', function (Blueprint $table) {
            $table->string('pan', 255)->change();
            $table->string('aadhar', 255)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dealer_company_documents', function (Blueprint $table) {
            $table->string('pan', 255)->change();
            $table->string('aadhar', 255)->change();
        });
    }
};
