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
            $table->string('urc_certificate', 250)->nullable();
            $table->longText('borrower_signed_cheque')->nullable();
            $table->longText('vehicle_signed_cheque')->nullable();
            $table->string('doh', 250)->nullable();
            $table->longText('borrower_other_docs')->nullable();
            $table->longText('vehicle_other_docs', 250)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('urc_certificate');
            $table->dropColumn('borrower_signed_cheque');
            $table->dropColumn('vehicle_signed_cheque');
            $table->dropColumn('doh');
            $table->dropColumn('borrower_other_docs');
            $table->dropColumn('vehicle_other_docs');
        });
    }
};
