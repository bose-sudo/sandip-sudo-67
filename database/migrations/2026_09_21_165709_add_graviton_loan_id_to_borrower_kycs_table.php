<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrower_kycs', function (Blueprint $table) {
            if (!Schema::hasColumn('borrower_kycs', 'graviton_loan_id')) {
                $table->unsignedBigInteger('graviton_loan_id')->nullable()->after('graviton_client_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('borrower_kycs', function (Blueprint $table) {
            $table->dropColumn('graviton_loan_id');
        });
    }
};