<?php

use App\Data\CircuitPermitData;

test('lets the call through without waiting when the permit is granted', function () {
    $permit = CircuitPermitData::granted();

    expect($permit)
        ->granted->toBeTrue()
        ->waitMilliseconds->toBe(0)
        ->isProbe()->toBeFalse();
});

test('lets the call through as the probe and keeps its lease when the permit is a probe', function () {
    $permit = CircuitPermitData::probe('1763114400000');

    expect($permit)
        ->granted->toBeTrue()
        ->waitMilliseconds->toBe(0)
        ->probeLease->toBe('1763114400000')
        ->isProbe()->toBeTrue();
});

test('refuses the call and tells how long to wait when the permit is denied', function () {
    $permit = CircuitPermitData::denied(15000);

    expect($permit)
        ->granted->toBeFalse()
        ->waitMilliseconds->toBe(15000);
});
