<?php

/*
|--------------------------------------------------------------------------
| Health Check Messages
|--------------------------------------------------------------------------
|
| Reasons reported by the public health endpoint. They must not reveal
| internals such as hosts or exception messages.
|
*/

return [

    'check_failed' => 'The :check check failed.',

    'horizon_inactive' => 'Horizon is not running, so no notification is being delivered.',

    'gateway_not_configured' => 'The notification gateway URL is not configured.',

];
