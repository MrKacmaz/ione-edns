<?php

use App\Data\CircuitPermitData;

test('lets the call through without waiting when the permit is granted', function () {
    $permit = CircuitPermitData::granted();

    expect($permit)
        ->granted->toBeTrue()
        ->waitMilliseconds->toBe(0);
});

test('refuses the call and tells how long to wait when the permit is denied', function () {
    $permit = CircuitPermitData::denied(15000);

    expect($permit)
        ->granted->toBeFalse()
        ->waitMilliseconds->toBe(15000);
});
