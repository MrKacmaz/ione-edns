<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The earliest moment the next delivery attempt may run, set when an attempt
     * failed and a retry was scheduled. A worker claims a notification only once
     * this time (or else its scheduled_at) has come, so a duplicate or early job
     * can neither skip the backoff nor send ahead of the schedule.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('next_attempt_at', precision: 6)->nullable()->after('scheduled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('next_attempt_at');
        });
    }
};
