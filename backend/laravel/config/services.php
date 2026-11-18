<?php

return [
    'approval_sms' => [
        'url' => env('APPROVAL_SMS_URL'),
        'token' => env('APPROVAL_SMS_TOKEN'),
        'sender_id' => env('APPROVAL_SMS_SENDER_ID', 'NEXORA'),
        'ttl_minutes' => max(1, min(15, (int) env('APPROVAL_OTP_TTL_MINUTES', 5))),
    ],
];
