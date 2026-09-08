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
            $table->string('borrower_name_declaration', 255)->nullable();
            $table->string('coapplicant_name_declaration', 255)->nullable();
            $table->string('guarantor_name_declaration', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('borrower_name_declaration');
            $table->dropColumn('coapplicant_name_declaration');
            $table->dropColumn('guarantor_name_declaration');
        });
    }
};
