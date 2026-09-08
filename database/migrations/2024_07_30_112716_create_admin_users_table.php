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
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('mobile', 15)->unique();
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_confirmation_code')->nullable();
            $table->string('alternative_mobile_1')->nullable();
            $table->string('otp', 14)->nullable();
            $table->integer('department');
            $table->integer('user_type');
            $table->boolean('is_active')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->integer('updated_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('auth_token')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index('department');
            $table->index('user_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
