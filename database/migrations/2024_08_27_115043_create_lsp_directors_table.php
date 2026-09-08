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
        Schema::create('lsp_directors', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('lsp_id');
            $table->string('name', 255);
            $table->string('mobile', 15);
            $table->string('alternative_mobile', 15)->nullable();
            $table->string('email', 15);
            $table->string('aadhar', 15)->nullable();
            $table->string('pan', 15)->nullable();
            $table->timestamps();

            $table->index('lsp_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lsp_directors');
    }
};
