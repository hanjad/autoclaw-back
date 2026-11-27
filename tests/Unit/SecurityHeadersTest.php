<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('adds API security headers and only sends HSTS over HTTPS', function (string $scheme, ?string $hsts) {
    $original = new Response('payload', 202);
    $response = (new SecurityHeaders)->handle(Request::create("$scheme://example.test/api/test"), fn () => $original);

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getContent())->toBe('payload')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Permissions-Policy'))->toBe('camera=(), microphone=(), geolocation=()')
        ->and($response->headers->get('Strict-Transport-Security'))->toBe($hsts);
})->with([
    'HTTPS' => ['https', 'max-age=31536000; includeSubDomains'],
    'HTTP' => ['http', null],
]);
