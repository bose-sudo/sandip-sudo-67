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
        Schema::table('documents', function (Blueprint $table) {
            $table->string('borrower_rent_doc', 255)->nullable();
            $table->string('coapplicant_rent_doc', 255)->nullable();
            $table->string('guarantor_rent_doc', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('borrower_rent_doc');
            $table->dropColumn('coapplicant_rent_doc');
            $table->dropColumn('guarantor_rent_doc');
        });
    }
};
