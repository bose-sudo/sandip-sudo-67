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
        Schema::table('borrower_kycs', function (Blueprint $table) {
            $table->boolean('skip_co_applicant')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrower_kycs', function (Blueprint $table) {
            $table->dropColumn('skip_co_applicant');
        });
    }
};
