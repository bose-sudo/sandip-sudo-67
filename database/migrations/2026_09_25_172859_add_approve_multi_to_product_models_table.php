<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('product_models', function (Blueprint $table) {
            $table->boolean('approve_multi')->default(false)->after('insurance_2nd_year');
        });
    }

    public function down()
    {
        Schema::table('product_models', function (Blueprint $table) {
            $table->dropColumn('approve_multi');
        });
    }
};