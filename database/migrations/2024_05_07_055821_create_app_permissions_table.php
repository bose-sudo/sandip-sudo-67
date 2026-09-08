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
        Schema::create('app_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 150)->unique();
            $table->boolean('user_personal_information')->default(false);
            $table->boolean('phone')->default(false);
            $table->boolean('location')->default(false);
            $table->boolean('camera')->default(false);
            $table->boolean('storage')->default(false);
            $table->boolean('third_party_accounts')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_permissions');
    }
};
