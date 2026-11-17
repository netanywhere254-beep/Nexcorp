<?php

return [
    'provider' => env('NEXCORE_AI_PROVIDER', 'openai-compatible'),
    'base_url' => env('NEXCORE_AI_BASE_URL', 'https://api.openai.com/v1'),
    'api_key' => env('NEXCORE_AI_API_KEY'),
    'model' => env('NEXCORE_AI_MODEL', 'gpt-4o-mini'),
    'timeout' => (int) env('NEXCORE_AI_TIMEOUT', 30),
    'max_tokens' => (int) env('NEXCORE_AI_MAX_TOKENS', 800),
    'enabled' => (bool) env('NEXCORE_AI_ENABLED', false),
];
