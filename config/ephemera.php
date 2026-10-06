<?php

$signedUrlTtl = (int) env('EPHEMERA_SIGNED_URL_TTL', 600);

return [
    'max_text_size' => env('EPHEMERA_MAX_TEXT_SIZE', 64 * 1024),
    'max_file_size' => env('EPHEMERA_MAX_FILE_SIZE', 50 * 1024 * 1024),
    'chunk_size' => env('EPHEMERA_CHUNK_SIZE', 4 * 1024 * 1024),
    'storage_disk' => env('EPHEMERA_STORAGE_DISK', 'local'),
    'expiry_options' => array_map('intval', explode(',', env('EPHEMERA_EXPIRY_OPTIONS', '10,60,1440,10080'))),
    'default_views' => env('EPHEMERA_DEFAULT_VIEWS', 3),
    'signed_url_ttl' => $signedUrlTtl,
    'purge_grace_period' => max((int) env('EPHEMERA_PURGE_GRACE_PERIOD', 660), $signedUrlTtl + 60),
    'delete_token_length' => env('EPHEMERA_DELETE_TOKEN_LENGTH', 32),
    'kdf_iterations' => max(600000, (int) env('EPHEMERA_KDF_ITERATIONS', 600000)),
    'rate_limits' => [
        'create_drop_per_ip' => env('EPHEMERA_RATE_CREATE', 20),
        'claim_per_ip' => env('EPHEMERA_RATE_CLAIM', 30),
    ],
];
