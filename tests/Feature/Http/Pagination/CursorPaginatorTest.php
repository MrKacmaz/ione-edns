<?php

use App\Http\Pagination\CursorPaginator;
use Carbon\CarbonImmutable;

test('writes dates into the cursor with their microseconds', function () {
    $items = [
        ['created_at' => CarbonImmutable::parse('2026-11-14 12:00:00.654321'), 'id' => 'b'],
        ['created_at' => CarbonImmutable::parse('2026-11-14 12:00:00.123456'), 'id' => 'a'],
    ];

    $paginator = new CursorPaginator($items, 1, null, ['parameters' => ['created_at', 'id']]);

    expect($paginator->nextCursor()?->toArray())->toBe([
        'created_at' => '2026-11-14 12:00:00.654321',
        'id' => 'b',
        '_pointsToNextItems' => true,
    ]);
});
