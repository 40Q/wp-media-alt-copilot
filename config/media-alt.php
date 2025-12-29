<?php

return [
    'default_provider' => env('MEDIA_ALT_PROVIDER', 'openai'),

    'autosuggest_on_upload' => env('MEDIA_ALT_AUTOSUGGEST_ON_UPLOAD', false),

    'auto_fill_empty_alt' => env('MEDIA_ALT_AUTO_FILL_EMPTY_ALT', false),

    'prompt' => [
        'max_words' => env('MEDIA_ALT_MAX_WORDS', 20),
        'tone' => env('MEDIA_ALT_TONE', 'neutral and descriptive'),
        'custom_instructions' => env('MEDIA_ALT_CUSTOM_INSTRUCTIONS', ''),
        'force_verbatim_text' => env('MEDIA_ALT_FORCE_VERBATIM_TEXT', false),
    ],

    'vision' => [
        'enabled' => true,
        // Modes: auto (try URL then base64), url (prefer URL), base64 (prefer base64). Falls back when preferred is unavailable.
        'mode' => env('MEDIA_ALT_VISION_MODE', 'auto'),
        'max_base64_bytes' => env('MEDIA_ALT_MAX_BASE64_BYTES', 1500000),
        'check_url' => env('MEDIA_ALT_CHECK_URL', false),
        // Optional: rewrite local hosts to a public host for vision models.
        'public_host' => env('MEDIA_ALT_PUBLIC_HOST'), // e.g., 40q.agency
        'public_scheme' => env('MEDIA_ALT_PUBLIC_SCHEME', 'https'),
    ],

    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'endpoint' => env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
            'max_tokens' => env('OPENAI_MAX_TOKENS', 120),
        ],
        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest'),
            'endpoint' => env('ANTHROPIC_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
            'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 180),
        ],
    ],
];
