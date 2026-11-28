<?php

namespace App\Support;

use Illuminate\Http\Request;

// The visitor's real IP address, for rate limits.
//
// Behind a hosting proxy, $request->ip() is the proxy's own address, so every visitor would share one
// rate-limit bucket. Some hosts (Render sits behind Cloudflare) pass the real address in a header that
// the edge sets itself. Name that header in CLIENT_IP_HEADER (e.g. CF-Connecting-IP) ONLY when your
// host guarantees the header is set by its edge. Otherwise leave it empty: a client could forge it.
class ClientIp
{
    public static function from(Request $request): string
    {
        $header = config('marketplace.client_ip_header');

        if ($header) {
            $ip = $request->headers->get($header);

            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return (string) $request->ip();
    }
}
