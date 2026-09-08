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
            $table->string('chasis_number', 255)->nullable();
            $table->string('motor_controller', 255)->nullable();
            $table->string('battery_image', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('chasis_number');
            $table->dropColumn('motor_controller');
            $table->dropColumn('battery_image');
        });
    }
};
