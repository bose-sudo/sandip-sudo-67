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
        Schema::create('borrower_addresses', function (Blueprint $table) {
            $table->id();
            $table->enum('resident_type', ['Owned', 'Rented']);
            $table->text('current_address');
            $table->decimal('years_living', 4 , 2);
            $table->text('permanent_address');
            $table->integer('family_members');
            $table->integer('earning_members');
            $table->decimal('family_income', 10, 2);
            $table->string('reference_one', 255)->nullable();
            $table->string('reference_two', 255)->nullable();
            $table->string('created_by', 30)->nullable();
            $table->string('updated_by', 30)->nullable();
            $table->string('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrower_addresses');
    }
};
