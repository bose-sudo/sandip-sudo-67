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
        Schema::table('lsp_executive_details', function (Blueprint $table) {
            $table->string('name', 255)->nullable();
            $table->string('mobile', 15)->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);

            $table->index('mobile');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lsp_executive_details', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->dropColumn('mobile');
            $table->dropColumn('email');
            $table->dropColumn('password');
            $table->dropColumn('is_active');
            $table->dropIndex('mobile');
            $table->dropIndex('email');
        });
    }
};
