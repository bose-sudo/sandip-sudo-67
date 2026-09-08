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
            $table->renameColumn('old_column_name', 'aadhar_back');
            $table->renameColumn('coAahar_back', 'coAadhar_back');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->renameColumn('aadhar_back', 'aahar_back');
            $table->renameColumn('coAadhar_back', 'coAahar_back');
        });
    }
};
