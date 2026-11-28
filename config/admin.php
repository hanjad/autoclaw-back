<?php

// First-admin settings. Read through config() so they keep working after `php artisan config:cache`.
return [
    'initial_email' => env('INITIAL_ADMIN_EMAIL'),
    'initial_password' => env('INITIAL_ADMIN_PASSWORD'),
    'initial_firstname' => env('INITIAL_ADMIN_FIRSTNAME', 'Admin'),
    'initial_surname' => env('INITIAL_ADMIN_SURNAME', 'User'),
    'initial_phone' => env('INITIAL_ADMIN_PHONE', '08000000000'),
];
