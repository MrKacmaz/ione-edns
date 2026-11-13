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
        Schema::create('notification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('notification_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at', precision: 6);

            $table->index(['notification_id', 'attempt']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_attempts');
    }
};
