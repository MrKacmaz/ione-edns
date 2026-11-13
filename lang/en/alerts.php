<?php

/*
|--------------------------------------------------------------------------
| Operator Alert Messages
|--------------------------------------------------------------------------
|
| Mails sent to the operators when a channel's delivery circuit opens or
| closes.
|
*/

return [

    'circuit_opened' => [
        'subject' => 'The :channel delivery circuit opened',
        'reason' => 'At least :rate% of at least :minimum calls to the :channel provider failed within :window seconds, so :channel deliveries are paused.',
        'next' => 'Waiting notifications keep their delivery attempts. A probe call is made in :cooldown seconds, and deliveries resume once a probe succeeds.',
    ],

    'circuit_closed' => [
        'subject' => 'The :channel delivery circuit closed',
        'reason' => 'A probe call to the :channel provider succeeded, so :channel deliveries have resumed.',
    ],

];
