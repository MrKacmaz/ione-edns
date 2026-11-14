<?php

use App\Data\RateLimitReservationData;

test('reserves a slot to send after the given wait', function () {
    $reservation = RateLimitReservationData::granted(250);

    expect($reservation)
        ->granted->toBeTrue()
        ->waitMilliseconds->toBe(250);
});

test('refuses a slot and tells when the next one opens', function () {
    $reservation = RateLimitReservationData::denied(1000);

    expect($reservation)
        ->granted->toBeFalse()
        ->waitMilliseconds->toBe(1000);
});
