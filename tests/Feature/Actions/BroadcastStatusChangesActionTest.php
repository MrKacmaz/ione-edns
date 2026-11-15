<?php

use App\Actions\BroadcastStatusChangesAction;
use App\Models\Notification;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;

test('pushes the broadcasts of all notifications together, onto the broadcasts queue', function () {
    $queue = new class(app()) extends QueueFake
    {
        /** @var list<array{queue: string|null, jobs: int}> */
        public array $bulkPushes = [];

        public function bulk($jobs, $data = '', $queue = null): void
        {
            $this->bulkPushes[] = ['queue' => $queue, 'jobs' => count($jobs)];

            parent::bulk($jobs, $data, $queue);
        }
    };
    Queue::swap($queue);
    $notifications = Notification::factory()->count(3)->create();

    app(BroadcastStatusChangesAction::class)->handle($notifications);

    expect($queue->bulkPushes)->toBe([['queue' => 'broadcasts', 'jobs' => 3]])
        ->and(announcedStatuses())->toBe($notifications->map(fn (Notification $notification): array => ['id' => $notification->id, 'status' => 'waiting'])->all());
});

test('announces the status the notifications had when it was called, once the transaction has committed', function () {
    Queue::fake([BroadcastEvent::class]);
    $notification = Notification::factory()->create();

    DB::transaction(function () use ($notification): void {
        app(BroadcastStatusChangesAction::class)->handle([$notification]);
        $notification->cancel();

        expect(announcedStatuses())->toBe([]);
    });

    expect(announcedStatuses())->toBe([
        ['id' => $notification->id, 'status' => 'waiting'],
        ['id' => $notification->id, 'status' => 'cancelled'],
    ]);
});

test('reports a queue that cannot be reached instead of failing the caller', function () {
    Exceptions::fake();
    Queue::swap(new class(app()) extends QueueFake
    {
        public function bulk($jobs, $data = '', $queue = null): never
        {
            throw new RuntimeException('Connection refused');
        }
    });

    app(BroadcastStatusChangesAction::class)->handle([Notification::factory()->create()]);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Connection refused');
});

test('pushes nothing for no notifications', function () {
    Queue::fake();

    app(BroadcastStatusChangesAction::class)->handle([]);

    Queue::assertNothingPushed();
});
