<?php
declare(strict_types=1);

return [
    'smtp' => [
        'host' => 'mail.mijndomein.nl',
        'port' => 465,
        'encryption' => 'ssl',
        'authentication' => true,
        'username' => 'no-reply@appsplace.nl',
        'password' => 'Rich@rd1982@@!',
        'timeout' => 20,
    ],

    'sender' => [
        'email' => 'no-reply@appsplace.nl',
        'name' => 'ATT Portaal',
    ],

    'reply_to' => [
        'email' => 'no-reply@appsplace.nl',
        'name' => 'ATT Portaal',
    ],

    'password_reset' => [
        'url' => 'https://185.62.56.221/reset-password.php',
        'expires_minutes' => 30,
    ],
];
