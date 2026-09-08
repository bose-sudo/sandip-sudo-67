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
        Schema::create('vkyc_web_responses', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id', 150)->nullable();
            $table->string('state', 50)->nullable();
            $table->string('status', 50)->nullable();
            $table->string('unique_id', 75)->nullable();
            $table->string('scheduled_slot_time', 100)->nullable();
            $table->string('borrower_id', 75)->nullable();
            $table->timestamps();
            
            $table->index('transaction_id');
            $table->index('unique_id');
            $table->index('borrower_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vkyc_web_responses');
    }
};
