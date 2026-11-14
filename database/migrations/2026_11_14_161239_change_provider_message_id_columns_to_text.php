<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tables that store the message id the provider returns.
     */
    private const array TABLES = ['notifications', 'notification_attempts'];

    /**
     * Run the migrations.
     *
     * The provider decides how long its message ids are. A longer id than the
     * column held made recording an accepted delivery fail after the provider had
     * already sent it, so the notification stayed in processing and was sent
     * again by every stale sweep.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->text('provider_message_id')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Fails when a stored message id is longer than 255 characters.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('provider_message_id')->nullable()->change();
            });
        }
    }
};
