<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graviton_reference_data', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60)->index();
            $table->unsignedBigInteger('graviton_id');
            $table->string('code', 150)->nullable();
            $table->string('label', 255)->nullable();
            $table->integer('position')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_mandatory')->default(false);
            $table->unsignedBigInteger('parent_graviton_id')->nullable()->index();
            $table->jsonb('raw_response')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'graviton_id'], 'uq_ref_type_gid');
            $table->index(['type', 'code'], 'idx_ref_type_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graviton_reference_data');
    }
};