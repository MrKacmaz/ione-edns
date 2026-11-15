<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * provider_message_id is text because the provider decides how long its ids
     * are; a longer id than the column held would make recording an accepted
     * delivery fail after it was already sent, so every stale sweep sent it again.
     *
     * next_attempt_at is the earliest moment the next delivery attempt may run,
     * set when a retry is scheduled. A worker claims a notification only once this
     * time (or else its scheduled_at) has come, so a duplicate or early job can
     * neither skip the backoff nor send ahead of the schedule.
     *
     * trace_id and span_id (32 and 16 hex characters) are the OpenTelemetry span
     * every delivery attempt of the notification is traced under: the request that
     * created it, or for a batch the notification's own trace. Null while tracing
     * is off.
     *
     * The owner-scoped indexes serve the listing and its status and channel
     * filters. Beyond them, (status, scheduled_at) serves
     * notifications:dispatch-due, (status, updated_at) serves
     * notifications:redispatch-stale, (status, failed_at) serves the failure counts
     * of the metrics, and template_id keeps deleting a template, which nulls the
     * column on its notifications, from scanning the whole table.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('notification_batches')->nullOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('priority', 16);
            $table->string('status', 16);
            $table->string('recipient', 512);
            $table->text('content');
            $table->string('idempotency_key')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->char('trace_id', 32)->nullable();
            $table->char('span_id', 16)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('provider_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('scheduled_at', precision: 6)->nullable();
            $table->timestamp('next_attempt_at', precision: 6)->nullable();
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
            $table->index(['status', 'scheduled_at']);
            $table->index(['status', 'updated_at']);
            $table->index(['status', 'failed_at']);
            $table->index('batch_id');
            $table->index('template_id');
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
