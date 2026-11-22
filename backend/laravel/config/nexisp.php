<?php

return [
    // Set a dedicated, stable random secret in production. Do not rotate it
    // without first re-indexing stored customer phone hashes.
    'phone_hash_key' => env('NEXISP_PHONE_HASH_KEY', env('APP_KEY')),
];
