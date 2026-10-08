<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrower_kycs', function (Blueprint $table) {
            $table->unsignedBigInteger('graviton_client_id')->nullable()->after('borrower_id');
            $table->decimal('dedupe_match_percentage', 5, 2)->nullable()->after('graviton_client_id');
            $table->json('dedupe_data')->nullable()->after('dedupe_match_percentage');
            $table->timestamp('dedupe_checked_at')->nullable()->after('dedupe_data');
            $table->enum('dedupe_status', ['pending', 'no_match', 'matched', 'failed'])
                  ->default('no_match')
                  ->after('dedupe_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('borrower_kycs', function (Blueprint $table) {
            $table->dropColumn([
                'graviton_client_id',
                'dedupe_match_percentage',
                'dedupe_data',
                'dedupe_checked_at',
                'dedupe_status',
            ]);
        });
    }
};