<?php

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        // Institution users (owners, staff, parents) — used by the tenant app.
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        // Quick ERP platform staff — the Super Admin console only.
        'platform' => [
            'driver' => 'session',
            'provider' => 'platform_admins',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => Modules\Identity\Models\User::class,
        ],
        'platform_admins' => [
            'driver' => 'eloquent',
            'model' => Modules\Platform\Models\PlatformAdmin::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60 * 24 * 7,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
