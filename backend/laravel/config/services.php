<?php

return [
    'trace_find_sms' => [
        'url' => env('TRACE_FIND_SMS_URL'),
        'token' => env('TRACE_FIND_SMS_TOKEN'),
    ],
    'nexora_staff_sms' => [
        'url' => env('NEXORA_STAFF_SMS_URL'),
        'token' => env('NEXORA_STAFF_SMS_TOKEN'),
    ],
    'nexora_public_sms' => [
        'url' => env('NEXORA_PUBLIC_SMS_URL'),
        'token' => env('NEXORA_PUBLIC_SMS_TOKEN'),
    ],
    'nexora_group_sms' => [
        'url' => env('NEXORA_GROUP_SMS_URL'),
        'token' => env('NEXORA_GROUP_SMS_TOKEN'),
    ],
    'nexora_group_ceo_bootstrap_key' => env('NEXORA_GROUP_CEO_BOOTSTRAP_KEY'),
    'nexora_replication' => [
        'url' => env('NEXORA_REPLICATION_URL'),
        'secret' => env('NEXORA_REPLICATION_SECRET'),
    ],
];
