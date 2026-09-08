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
        Schema::table('field_inspection_details', function (Blueprint $table) {
            $table->string('longitude', 20)->default(0);
            $table->string('latitude', 20)->default(0);
            $table->string('verification_url', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('field_inspection_details', function (Blueprint $table) {
            $table->dropColumn('longitude');
            $table->dropColumn('latitude');
            $table->dropColumn('verification_url');
        });
    }
};
