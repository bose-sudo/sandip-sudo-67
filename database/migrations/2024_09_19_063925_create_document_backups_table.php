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
        Schema::create('document_backups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('borrower_id');
            $table->string('flag_type', 255);
            $table->string('doc_type', 255);
            $table->string('previous_doc_url', 255)->nullable(); 
            $table->string('new_doc_url', 255)->nullable();      // New file URL
            $table->string('backup_file_url', 255)->nullable();  // Backup file URL
            $table->unsignedBigInteger('user_id')->nullable();        // User who uploaded the file
            $table->unsignedBigInteger('admin_user_id')->nullable();  // Admin ID (nullable) in case admin user edit the file
            $table->unsignedBigInteger('executive_id')->nullable();   // Executive ID (nullable) in case executive edit the file
            $table->string('status', 100)->nullable(); // Status
            $table->timestamps();

            $table->index('borrower_id');
            $table->index('flag_type');
            $table->index('doc_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_backups');
    }
};
