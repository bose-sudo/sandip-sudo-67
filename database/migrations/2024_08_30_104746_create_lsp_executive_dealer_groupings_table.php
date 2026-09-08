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
        Schema::create('lsp_executive_dealer_groupings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('executive_id');
            $table->bigInteger('dealer_id');
            $table->bigInteger('user_id');
            $table->timestamps();

            $table->index('executive_id');
            $table->index('dealer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lsp_executive_dealer_groupings');
    }
};
