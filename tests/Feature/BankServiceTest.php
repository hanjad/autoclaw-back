<?php

use App\Services\BankService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config(['services.paystack.secret' => 'test-secret']);
    Http::preventStrayRequests();
});

it('cleans and caches a sufficiently complete provider bank list', function () {
    $banks = array_map(fn ($i) => ['code' => (string) (100 + $i), 'name' => "Bank $i"], range(0, 9));
    $banks[0]['code'] = 100;
    $payload = [...array_reverse($banks), ['code' => '100', 'name' => 'Duplicate'], ['code' => '777']];
    Http::fake(['https://api.paystack.co/bank?*' => Http::response(['data' => $payload])]);

    $service = new BankService;
    $banks[0]['code'] = '100';
    expect($service->banks())->toBe($banks)
        ->and($service->banks())->toBe($banks);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-secret')
        && $request['country'] === 'nigeria'
        && $request['currency'] === 'NGN');
    Http::assertSentCount(1);
});

it('falls back to local bank codes for an incomplete list or an upstream failure', function (int $status) {
    Http::fake(['https://api.paystack.co/bank?*' => Http::response([
        'data' => [['code' => '777', 'name' => 'Incomplete']],
    ], $status)]);

    $banks = (new BankService)->banks();
    expect($banks)->toContain(['code' => '044', 'name' => 'Access Bank'])
        ->and(array_column($banks, 'code'))->not->toContain('777');
})->with([200, 503]);

it('uses manual review without contacting the provider when no key is configured', function () {
    config(['services.paystack.secret' => null]);
    Http::fake();

    $service = new BankService;
    expect($service->banks())->toContain(['code' => '044', 'name' => 'Access Bank'])
        ->and($service->resolve('0123456789', '044'))->toBe(['status' => 'unavailable', 'name' => null]);
    Http::assertNothingSent();
});

it('distinguishes a verified account, a bad account and an unavailable lookup', function (int $code, bool $success, string $status, ?string $name) {
    Http::fake(['https://api.paystack.co/bank/resolve?*' => Http::response([
        'status' => $success,
        'data' => ['account_name' => 'ADA OKAFOR'],
    ], $code)]);

    expect((new BankService)->resolve('0123456789', '044'))->toBe(['status' => $status, 'name' => $name]);
    Http::assertSent(fn (Request $request) => $request['account_number'] === '0123456789'
        && $request['bank_code'] === '044');
})->with([
    'found' => [200, true, 'verified', 'ADA OKAFOR'],
    'bad request' => [400, false, 'invalid', null],
    'not found' => [404, false, 'invalid', null],
    'invalid details' => [422, false, 'invalid', null],
    'rate limited by provider' => [429, false, 'unavailable', null],
    'unhealthy provider' => [503, false, 'unavailable', null],
    'provider declined in a success response' => [200, false, 'unavailable', null],
]);
