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
        Schema::table('document_backups', function (Blueprint $table) {
            $table->text('previous_doc_url')->nullable()->change();
            $table->text('new_doc_url')->nullable()->change();
            $table->text('backup_file_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_backups', function (Blueprint $table) {
            $table->string('previous_doc_url')->nullable()->change();
            $table->string('new_doc_url')->nullable()->change();
            $table->string('backup_file_url')->nullable()->change();
        });
    }
};
