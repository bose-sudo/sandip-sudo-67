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
        Schema::create('lsp_company_documents', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('lsp_id');
            $table->string('company_type', 100);
            $table->string('pan', 250)->nullable();
            $table->string('aadhar', 250)->nullable();
            $table->string('itr_file', 250)->nullable();
            $table->string('bank_statement', 250)->nullable();
            $table->string('rent_agreement', 250)->nullable();
            $table->string('utility_bill', 250)->nullable();
            $table->string('partnership_deed', 250)->nullable();
            $table->string('balance_sheet', 250)->nullable();
            $table->string('certificate_of_incorporation', 250)->nullable();
            $table->string('aoa', 255)->nullable();
            $table->string('moa', 255)->nullable();
            $table->timestamps();

            $table->index('lsp_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lsp_company_documents');
    }
};
