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
        Schema::table('dealers', function (Blueprint $table) {
            $table->date('dob')->nullable();
            $table->string('mobile', 15)->nullable();
            $table->boolean('is_mobile_verified')->default(false);
            $table->string('alternative_number', 15)->nullable();
            $table->string('email', 255)->nullable();
            $table->boolean('is_email_verified')->default(false);
            $table->string('pan', 15)->nullable();
            $table->boolean('is_pan_verified')->default(false);
            $table->string('aadhar', 20)->nullable();
            $table->string('landmark', 255)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->text('address_line_1')->nullable();
            $table->text('address_line_2')->nullable();
            $table->string('dealer_loi', 255)->nullable();
            $table->string('bank_statement', 255)->nullable();
            $table->longText('cibil_report')->nullable();
            $table->string('trade_certificate', 255)->nullable();
            $table->string('gst_certificate', 255)->nullable();
            $table->string('security_cheque_1', 255)->nullable();
            $table->string('security_cheque_2', 255)->nullable();
            $table->string('security_cheque_3', 255)->nullable();
            $table->string('security_cheque_4', 255)->nullable();
            $table->string('rent_aggreement', 255)->nullable();
            $table->string('dealer_image_1', 255)->nullable();
            $table->string('dealer_image_2', 255)->nullable();
            $table->string('showroom_img_1', 255)->nullable();
            $table->string('showroom_img_2', 255)->nullable();
            $table->string('showroom_img_3', 255)->nullable();
            $table->string('showroom_img_4', 255)->nullable();
            $table->string('itr_file', 255)->nullable();
            $table->string('balance_sheet', 255)->nullable();
            
            $table->index('pan');
            $table->index('mobile');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            $table->dropColumn('dob');
            $table->dropColumn('mobile');
            $table->dropColumn('is_mobile_verified');
            $table->dropColumn('alternative_number');
            $table->dropColumn('email');
            $table->dropColumn('is_email_verified');
            $table->dropColumn('pan');
            $table->dropColumn('is_pan_verified');
            $table->dropColumn('aadhar');
            $table->dropColumn('landmark');
            $table->dropColumn('city');
            $table->dropColumn('pincode');
            $table->dropColumn('address_line_1');
            $table->dropColumn('address_line_2');
            $table->dropColumn('dealer_loi');
            $table->dropColumn('bank_statement');
            $table->dropColumn('cibil_report');
            $table->dropColumn('trade_certificate');
            $table->dropColumn('gst_certificate');
            $table->dropColumn('security_cheque_1');
            $table->dropColumn('security_cheque_2');
            $table->dropColumn('security_cheque_3');
            $table->dropColumn('security_cheque_4');
            $table->dropColumn('rent_aggreement');
            $table->dropColumn('dealer_image_1');
            $table->dropColumn('dealer_image_2');
            $table->dropColumn('showroom_img_1');
            $table->dropColumn('showroom_img_2');
            $table->dropColumn('showroom_img_3');
            $table->dropColumn('showroom_img_4');
            $table->dropColumn('itr_file');
            $table->dropColumn('balance_sheet');
        });
    }
};
