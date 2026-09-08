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
        Schema::create('product_upload_excels', function (Blueprint $table) {
            $table->id();
            $table->string('file_name', 255);
            $table->string('status', 50);
            $table->text('reason')->nullable();
            $table->bigInteger('uploaded_by');
            $table->bigInteger('updated_by');
            $table->longText('errors')->nullable();
            $table->timestamps();

            $table->index('uploaded_by');
            $table->index('updated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_upload_excels');
    }
};
