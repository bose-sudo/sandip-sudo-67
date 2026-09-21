<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('graviton_application_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('graviton_application_logs', 'step')) {
                $table->string('step')->nullable()->after('borrower_id');
            }
            if (!Schema::hasColumn('graviton_application_logs', 'entity_type')) {
                $table->string('entity_type')->nullable()->after('step');
            }
        });
    }

    public function down(): void
    {
        Schema::table('graviton_application_logs', function (Blueprint $table) {
            $table->dropColumn(['step', 'entity_type']);
        });
    }
};