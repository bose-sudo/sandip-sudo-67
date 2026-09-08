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
        Schema::create('npci_bank_lists', function (Blueprint $table) {
            $table->id();
            $table->string('bank_id')->unique();
            $table->string('bank_name');
            $table->boolean('netbank_flag')->default(false);
            $table->boolean('card_flag')->default(false);
            $table->boolean('adhar_flag')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('npci_bank_lists');
    }
};
