<?php

use App\Models\Shop;
use App\Models\User;
use App\Services\BankService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('x', 32)),
        'marketplace.max_shops_per_vendor' => 1,
        'marketplace.private_disk' => 'local',
        'marketplace.public_disk' => 'public',
    ]);
    Storage::fake('local');
    Storage::fake('public');
    $this->vendor = User::factory()->create(['role' => 'vendor', 'firstname' => 'Ada', 'surname' => 'Okafor']);
    Sanctum::actingAs($this->vendor);

    $this->profile = [
        'name' => 'Eko Sunrise',
        'phone' => '08012345678',
        'address' => '12 Market Road',
        'city' => 'Ikeja',
        'state' => 'Lagos',
    ];
    $this->payout = [
        'business_type' => 'individual',
        'id_type' => 'nin',
        'bank_code' => '044',
        'account_number' => '0123456789',
        'account_name' => 'Ada Okafor',
        'accept_terms' => true,
    ];
    $this->documents = [
        'id_document' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'),
        'selfie' => UploadedFile::fake()->image('selfie.jpg'),
        'address_proof' => UploadedFile::fake()->image('proof.png'),
    ];
});

it('shows only the current vendor shop and prevents duplicate creation and injected verification fields', function () {
    $other = User::factory()->create();
    $this->createShop($other, ['name' => 'Other Shop']);
    $this->getJson('/api/vendor/shop')->assertOk()->assertExactJson(['data' => null]);
    $this->putJson('/api/vendor/shop', ['city' => 'Lagos'])->assertNotFound();

    $this->postJson('/api/vendor/shop', array_replace($this->profile, ['phone' => '0123']))
        ->assertUnprocessable()->assertJsonValidationErrors('phone');
    $this->postJson('/api/vendor/shop', $this->profile + [
        'vendor_id' => $other->id,
        'verification_status' => Shop::APPROVED,
        'is_verified' => true,
    ])->assertCreated()
        ->assertJsonPath('data.vendor_id', $this->vendor->id)
        ->assertJsonPath('data.country', 'Nigeria');

    $this->getJson('/api/vendor/shop')->assertOk()
        ->assertJsonPath('data.name', 'Eko Sunrise')
        ->assertJsonPath('data.verification_status', Shop::UNSUBMITTED)
        ->assertJsonPath('data.is_verified', false);

    $this->postJson('/api/vendor/shop', array_replace($this->profile, ['name' => 'Second Shop']))
        ->assertUnprocessable();
    expect(Shop::where('vendor_id', $this->vendor->id)->count())->toBe(1);
});

it('limits identity edits according to verification status while allowing profile edits', function (string $status, bool $locked) {
    $shop = $this->createShop($this->vendor, ['verification_status' => $status]);

    $response = $this->putJson('/api/vendor/shop', ['city' => 'Ibadan']);
    if ($locked) {
        $response->assertUnprocessable()->assertJsonValidationErrors('city');
        expect($shop->fresh()->city)->toBe('Ikeja');
    } else {
        $response->assertOk()->assertJsonPath('data.city', 'Ibadan');
    }

    $this->putJson('/api/vendor/shop', ['name' => $shop->name, 'description' => 'New profile'])
        ->assertOk()->assertJsonPath('data.description', 'New profile');
})->with([
    'unsubmitted' => [Shop::UNSUBMITTED, false],
    'rejected' => [Shop::REJECTED, false],
    'pending' => [Shop::PENDING, true],
    'approved' => [Shop::APPROVED, true],
    'suspended' => [Shop::SUSPENDED, true],
]);

it('replaces a logo on the public disk without writing it to private storage', function () {
    Storage::disk('public')->put('shop_logos/old.jpg', 'previous');
    $shop = $this->createShop($this->vendor, ['logo' => 'shop_logos/old.jpg']);

    $response = $this->post('/api/vendor/shop', [
        '_method' => 'PUT',
        'logo' => UploadedFile::fake()->image('replacement.jpg'),
    ])->assertOk();
    $path = $shop->fresh()->logo;

    Storage::disk('public')->assertExists($path);
    Storage::disk('public')->assertMissing('shop_logos/old.jpg');
    Storage::disk('local')->assertMissing($path);
    $response->assertJsonPath('data.logo_url', asset("storage/$path"));
});

it('requires verification documents, accepted terms and a valid account number before bank lookup', function () {
    $shop = $this->createShop($this->vendor);
    $this->mock(BankService::class)->shouldNotReceive('banks');
    $payload = array_replace($this->payout, [
        'business_type' => 'registered_business',
        'account_number' => '123456789',
        'accept_terms' => false,
    ]);

    $this->postJson('/api/vendor/shop/verification', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'id_document', 'selfie', 'address_proof', 'cac_number', 'cac_certificate', 'account_number', 'accept_terms',
        ]);
    expect($shop->fresh()->verification_status)->toBe(Shop::UNSUBMITTED);
});

it('requires an existing shop and a verified email before starting review', function () {
    $this->postJson('/api/vendor/shop/verification')->assertNotFound();
    $this->createShop($this->vendor);
    $this->vendor->forceFill(['email_verified_at' => null])->save();

    $this->postJson('/api/vendor/shop/verification')->assertForbidden();
});

it('rejects submissions in a state that cannot enter review', function (string $status) {
    $this->createShop($this->vendor, ['verification_status' => $status]);
    $this->postJson('/api/vendor/shop/verification')->assertUnprocessable();
})->with([Shop::PENDING, Shop::APPROVED, Shop::SUSPENDED]);

it('rejects unknown banks and accounts without saving any KYC', function (bool $knownBank, string $error) {
    $shop = $this->createShop($this->vendor);
    $banks = $this->mock(BankService::class);
    $banks->shouldReceive('banks')->once()->andReturn([['code' => '044', 'name' => 'Access Bank']]);
    if ($knownBank) {
        $banks->shouldReceive('resolve')->once()->with('0123456789', '044')
            ->andReturn(['status' => 'invalid', 'name' => null]);
    } else {
        $banks->shouldNotReceive('resolve');
    }

    $this->postJson('/api/vendor/shop/verification', array_replace(
        $this->payout + $this->documents,
        ['bank_code' => $knownBank ? '044' : '000'],
    ))->assertUnprocessable()->assertJsonValidationErrors($error);

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($shop->fresh()->verification_status)->toBe(Shop::UNSUBMITTED)
        ->and($shop->fresh()->account_number)->toBeNull();
})->with([
    'unknown bank' => [false, 'bank_code'],
    'invalid account' => [true, 'account_number'],
]);

it('queues review with private documents, an encrypted account number and a masked response', function () {
    $shop = $this->createShop($this->vendor);
    $banks = $this->mock(BankService::class);
    $banks->shouldReceive('banks')->once()->andReturn([['code' => '044', 'name' => 'Access Bank']]);
    $banks->shouldReceive('resolve')->once()->with('0123456789', '044')
        ->andReturn(['status' => 'verified', 'name' => 'SUNRISE EKO LTD']);

    $response = $this->postJson('/api/vendor/shop/verification', array_replace($this->payout, [
        'business_type' => 'registered_business',
        'cac_number' => 'rc- 123456',
        'cac_certificate' => UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf'),
    ]) + $this->documents);

    $response->assertOk()
        ->assertJsonPath('data.verification_status', Shop::PENDING)
        ->assertJsonPath('data.is_verified', false)
        ->assertJsonPath('data.kyc.cac_number', 'RC123456')
        ->assertJsonPath('data.kyc.account_last4', '6789')
        ->assertJsonMissingPath('data.kyc.account_number')
        ->assertJsonMissingPath('data.account_number')
        ->assertJsonPath('data.kyc.bank_name_match', true);

    $shop->refresh();
    foreach (['id_document', 'selfie', 'address_proof', 'cac_certificate'] as $field) {
        Storage::disk('local')->assertExists($shop->{$field});
        Storage::disk('public')->assertMissing($shop->{$field});
        $response->assertJsonPath("data.kyc.has_$field", true)
            ->assertJsonMissingPath("data.kyc.$field")
            ->assertJsonMissingPath("data.$field");
    }
    expect($shop->account_number)->toBe('0123456789')
        ->and(DB::table('shops')->where('id', $shop->id)->value('account_number'))->not->toBe('0123456789')
        ->and($shop->terms_accepted_at)->not->toBeNull()
        ->and($shop->submitted_at)->not->toBeNull();
});

it('reuses existing documents after rejection and distinguishes a mismatch from manual review', function (string $status, ?string $holder, ?bool $match) {
    $shop = $this->createShop($this->vendor, [
        'verification_status' => Shop::REJECTED,
        'rejection_reason' => 'Please check the payout details.',
        'id_document' => 'kyc/id.pdf',
        'selfie' => 'kyc/selfie.jpg',
        'address_proof' => 'kyc/proof.pdf',
    ]);
    foreach ([$shop->id_document, $shop->selfie, $shop->address_proof] as $path) {
        Storage::disk('local')->put($path, 'previous upload');
    }
    $banks = $this->mock(BankService::class);
    $banks->shouldReceive('banks')->once()->andReturn([['code' => '044', 'name' => 'Access Bank']]);
    $banks->shouldReceive('resolve')->once()->andReturn(['status' => $status, 'name' => $holder]);

    $this->postJson('/api/vendor/shop/verification', $this->payout)
        ->assertOk()
        ->assertJsonPath('data.verification_status', Shop::PENDING)
        ->assertJsonPath('data.rejection_reason', null)
        ->assertJsonPath('data.kyc.bank_name_match', $match);

    expect($shop->fresh()->id_document)->toBe('kyc/id.pdf')
        ->and(Storage::disk('local')->allFiles())->toHaveCount(3);
})->with([
    'provider unavailable' => ['unavailable', null, null],
    'holder does not match' => ['verified', 'Chika Nwosu', false],
]);
