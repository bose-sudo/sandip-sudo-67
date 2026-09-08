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
        Schema::table('vendor_service_apis', function (Blueprint $table) {
            $table->bigInteger('borrower_id')->nullable();
            $table->string('doc_id', 50)->nullable();

            $table->index('borrower_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_service_apis', function (Blueprint $table) {
            $table->dropColumn('borrower_id');
            $table->dropColumn('doc_id');
        });
    }
};
