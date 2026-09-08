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
            $table->string('rto_document', 255)->nullable();
            $table->string('mmr', 255)->nullable();
            $table->string('vehicle_image', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('rto_document');
            $table->dropColumn('mmr');
            $table->dropColumn('vehicle_image');
        });
    }
};
