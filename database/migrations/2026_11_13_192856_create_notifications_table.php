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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('notification_batches')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('priority', 16);
            $table->string('status', 16);
            $table->string('recipient', 512);
            $table->text('content');
            $table->string('idempotency_key')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('scheduled_at', precision: 6)->nullable();
            $table->timestamp('queued_at', precision: 6)->nullable();
            $table->timestamp('sent_at', precision: 6)->nullable();
            $table->timestamp('failed_at', precision: 6)->nullable();
            $table->timestamp('cancelled_at', precision: 6)->nullable();
            $table->timestamps(precision: 6);

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['user_id', 'channel', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
