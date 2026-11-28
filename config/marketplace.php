<?php

return [

    // How many shops one vendor account may own
    'max_shops_per_vendor' => 1,

    // false: unverified shops are listed but labelled "Unverified".
    // true:  only verified shops' products appear to the public (strictest anti-scam setting).
    'require_verified_to_list' => (bool) env('MARKETPLACE_REQUIRE_VERIFIED', false),

    // Where uploads go (names of disks in config/filesystems.php).
    // public_disk: product images and shop logos (served to buyers). private_disk: verification documents (never public).
    'public_disk' => env('PUBLIC_DISK', 'public'),
    'private_disk' => env('PRIVATE_DISK', 'local'),

    // Header that carries the visitor's real IP when your host's edge sets it (Render: CF-Connecting-IP).
    // Leave empty when unsure; see App\Support\ClientIp.
    'client_ip_header' => env('CLIENT_IP_HEADER'),

    // How long the Nigerian bank list is cached (hours)
    'banks_cache_hours' => 24,

];
