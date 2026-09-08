<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\table;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('access_roles', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_type_id');
            $table->bigInteger('submenu_id');
            $table->string('permission', 255);
            $table->timestamps();

            $table->index('user_type_id');
            $table->index('submenu_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_roles');
    }
};
