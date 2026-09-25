<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('oem_product_upload_excels', function (Blueprint $table) {
            $table->boolean('approve_multi')->default(false)->after('updated_by');
        });
    }

    public function down()
    {
        Schema::table('oem_product_upload_excels', function (Blueprint $table) {
            $table->dropColumn('approve_multi');
        });
    }
};