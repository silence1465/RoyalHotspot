<?php

return [
    // Personal access tokens are deliberately short-lived. A stolen
    // browser token must not remain valid forever.
    'expiration' => (int) env('SANCTUM_EXPIRATION', 480),
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
