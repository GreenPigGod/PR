<?php
declare(strict_types=1);

return [
    'lw' => [
        'client_id'     => '',
        'client_secret' => '',
        'redirect_uri'  => 'https://your-domain.com/pr/oauth/callback.php',
        'authorize_url' => 'https://auth.worksmobile.com/oauth2/v2.0/authorize',
        'token_url'     => 'https://auth.worksmobile.com/oauth2/v2.0/token',
        'user_me_url'   => 'https://www.worksapis.com/v1.0/users/me',
    ],

    'for_users' => [
        'redirect_uri' => 'https://your-domain.com/pr/forUsers/oauth_callback.php',
        'return_url'   => 'https://your-domain.com/pr/forUsers/auth_bridge.php',
    ],

    'app' => [
        'return_url'    => 'https://your-domain.com/pr/app/bridge.php',
        'deeplink_base' => 'myapp://callback?session=',
    ],

    'sec' => [
        'state_ttl'          => 600,
        'done_ttl'           => 180,
        'session_save_path'  => __DIR__ . '/_sessions',
        'app_session_ttl'    => 60 * 60 * 24 * 14,
        'app_session_slide'  => true,
    ],

    'db' => [
        'host' => '',
        'name' => '',
        'user' => '',
        'pass' => '',
    ],
];
