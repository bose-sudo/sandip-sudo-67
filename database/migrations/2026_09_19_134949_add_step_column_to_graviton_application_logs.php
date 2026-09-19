<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('graviton_application_logs', function (Blueprint $table) {
            $table->string('step')->nullable()->after('borrower_id');
        });
    }

    public function down(): void
    {
        Schema::table('graviton_application_logs', function (Blueprint $table) {
            $table->dropColumn('step');
        });
    }
};