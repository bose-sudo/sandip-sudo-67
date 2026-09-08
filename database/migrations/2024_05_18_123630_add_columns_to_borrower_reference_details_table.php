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
        Schema::table('borrower_reference_details', function (Blueprint $table) {
            $table->string('latitude_1', 50)->nullable();
            $table->string('longitude_1', 50)->nullable();
            $table->longText('verification_url_1')->nullable();
            $table->string('shortend_url_1', 150)->nullable();
            $table->boolean('is_verified_1')->default(false);
            $table->string('latitude_2', 50)->nullable();
            $table->string('longitude_2', 50)->nullable();
            $table->longText('verification_url_2')->nullable();
            $table->string('shortend_url_2', 150)->nullable();
            $table->boolean('is_verified_2')->default(false);

            $table->index('shortend_url_1');
            $table->index('shortend_url_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrower_reference_details', function (Blueprint $table) {
            $table->dropColumn('latitude_1');
            $table->dropColumn('longitude_1');
            $table->dropColumn('verification_url_1');
            $table->dropColumn('shortend_url_1');
            $table->dropColumn('is_verified_1');
            $table->dropColumn('latitude_2');
            $table->dropColumn('longitude_2');
            $table->dropColumn('verification_url_2');
            $table->dropColumn('shortend_url_2');
            $table->dropColumn('is_verified_2');
        });
    }
};
