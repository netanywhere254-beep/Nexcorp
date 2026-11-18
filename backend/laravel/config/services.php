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
];
