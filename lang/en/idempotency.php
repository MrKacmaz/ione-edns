<?php

/*
|--------------------------------------------------------------------------
| Idempotency Messages
|--------------------------------------------------------------------------
|
| Responses of requests sent with an idempotency key header that cannot be
| handled: an invalid key, a key still in use, or a key reused with a
| different payload.
|
*/

return [

    'invalid_key' => 'The :header header must be a non-empty string of at most :max characters.',

    'in_progress' => 'A request with this :header is still being processed. Retry later.',

    'payload_mismatch' => 'This :header was already used with a different request payload.',

];
