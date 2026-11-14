<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * (status, updated_at) serves notifications:redispatch-stale, which looks every
     * minute for processing and queued notifications untouched for too long.
     * (status, failed_at) serves the failure count of the metrics, collected every
     * five seconds over the last five minutes and hour.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['status', 'updated_at']);
            $table->index(['status', 'failed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at']);
            $table->dropIndex(['status', 'failed_at']);
        });
    }
};
