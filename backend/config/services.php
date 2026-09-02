<?php

return [

    // Merge this into Laravel's default config/services.php — don't
    // replace the whole file, Laravel ships with mailgun/postmark/ses
    // entries here too.

    'paystack' => [
        'secret_key'   => env('PAYSTACK_SECRET_KEY'),
        'public_key'   => env('PAYSTACK_PUBLIC_KEY'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),
        'currency'     => env('PAYSTACK_CURRENCY', 'GHS'),
        'fallback_email_domain' => env('PAYSTACK_FALLBACK_EMAIL_DOMAIN'),
    ],

    // Royal WiFi extension — shared secret the SMS Forwarder app presents
    // as a Bearer token on POST /api/v1/webhooks/sms-payment. See
    // app/Http/Middleware/VerifySmsForwarderToken.php.
    'sms_forwarder' => [
        'token' => env('SMS_FORWARDER_TOKEN'),
    ],

    // The React frontend's public URL — separate app, separate origin.
    // Was already in .env.example (used implicitly by CORS setup) but
    // never had a config() key to read through from PHP code until the
    // MikroTik login-page generator (MikrotikLoginPageController) needed
    // to build an absolute redirect URL pointing at it.
    'frontend' => [
        'url' => env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    // This Laravel app's own public URL — needed so RouterOS's /tool
    // fetch has something to actually download the login page FROM (see
    // MikrotikService::fetchLoginPage()). Read directly via env() rather
    // than config('app.url') since config/app.php was never published
    // in this project (see docs/PROJECT_OVERVIEW.md) — this avoids
    // depending on Laravel's internal default resolution for a key that
    // matters for a real network request, not just cosmetic.
    'backend' => [
        'url' => env('APP_URL', 'http://localhost:8000'),
    ],

];
