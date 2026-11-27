<?php

use App\Models\Shop;
use App\Models\User;
use App\Services\BankService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.key' => 'base64:' . base64_encode(random_bytes(32)),
        'services.paystack.secret' => null,
    ]);

    $this->vendor = User::factory()->create();
    $this->shop = Shop::create([
        'vendor_id' => $this->vendor->id,
        'name' => 'Sample Shop',
        'address' => '1 Sample Street',
        'city' => 'Ikeja',
        'state' => 'Lagos',
        'country' => 'Nigeria',
    ])->refresh();

    $this->disk = Storage::fake(config('marketplace.private_disk'));
});

function shopVerificationPayload(array $files = []): array
{
    return $files + [
        'business_type' => 'individual',
        'id_type' => 'nin',
        'bank_code' => '044',
        'account_number' => '1234567890',
        'account_name' => 'Sample Account',
        'accept_terms' => 'yes',
    ];
}

function addRejectedShopDocuments(Shop $shop, $disk): array
{
    $paths = [];
    foreach (['id_document', 'selfie', 'address_proof'] as $field) {
        $paths[$field] = "shop_kyc/{$shop->id}/old-{$field}.jpg";
        $disk->put($paths[$field], 'original content');
    }

    $shop->forceFill($paths + ['verification_status' => Shop::REJECTED, 'submitted_at' => now()])->save();

    return $paths;
}

test('suspension does not make a never submitted shop eligible for approval', function () {
    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    $url = "/api/admin/shops/{$this->shop->id}/verification";

    $this->putJson($url, ['decision' => 'suspend', 'reason' => 'Review is required.'])->assertOk();
    $this->putJson($url, ['decision' => 'approve'])->assertUnprocessable();
    expect($this->shop->refresh()->verification_status)->toBe(Shop::SUSPENDED);
});

test('a suspended shop can be approved if it was previously submitted', function () {
    $this->shop->forceFill(['verification_status' => Shop::PENDING, 'submitted_at' => now()])->save();
    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    $url = "/api/admin/shops/{$this->shop->id}/verification";

    $this->putJson($url, ['decision' => 'suspend', 'reason' => 'Review is required.'])->assertOk();
    $this->putJson($url, ['decision' => 'approve'])->assertOk()
        ->assertJsonPath('data.verification_status', Shop::APPROVED);
});

test('submission respects status changes made during bank lookup', function () {
    Sanctum::actingAs($this->vendor);
    $paths = addRejectedShopDocuments($this->shop, $this->disk);

    $this->mock(BankService::class, function ($banks) {
        $banks->shouldReceive('banks')->andReturn([['code' => '044', 'name' => 'Example Bank']]);
        $banks->shouldReceive('resolve')->once()->andReturnUsing(function () {
            $this->shop->forceFill(['verification_status' => Shop::SUSPENDED])->save();

            return ['status' => 'unavailable', 'name' => null];
        });
    });

    $this->post('/api/vendor/shop/verification', shopVerificationPayload([
        'id_document' => UploadedFile::fake()->image('replacement.jpg'),
    ]), ['Accept' => 'application/json'])->assertUnprocessable();

    expect($this->shop->refresh()->verification_status)->toBe(Shop::SUSPENDED);
    expect($this->shop->id_document)->toBe($paths['id_document']);
    expect($this->disk->allFiles())->toEqualCanonicalizing(array_values($paths));
});

test('submitted shops keep identity edits saved before submission and reject later edits', function () {
    Sanctum::actingAs($this->vendor);
    addRejectedShopDocuments($this->shop, $this->disk);

    $this->putJson('/api/vendor/shop', ['name' => 'Updated Shop'])->assertOk();
    $this->postJson('/api/vendor/shop/verification', shopVerificationPayload())
        ->assertOk()->assertJsonPath('data.name', 'Updated Shop');
    $this->putJson('/api/vendor/shop', ['name' => 'Another Shop'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    expect($this->shop->refresh()->name)->toBe('Updated Shop');
});

test('resubmission replaces uploaded documents and retains omitted documents', function () {
    Sanctum::actingAs($this->vendor);
    $paths = addRejectedShopDocuments($this->shop, $this->disk);

    $this->post('/api/vendor/shop/verification', shopVerificationPayload([
        'id_document' => UploadedFile::fake()->image('replacement.jpg'),
    ]), ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('data.verification_status', Shop::PENDING);

    $this->disk->assertMissing($paths['id_document']);
    $this->disk->assertExists([$this->shop->refresh()->id_document, $paths['selfie'], $paths['address_proof']]);
});

test('failed resubmissions retain existing documents and discard staged replacements', function (string $failure) {
    Sanctum::actingAs($this->vendor);
    $paths = addRejectedShopDocuments($this->shop, $this->disk);
    $files = ['id_document' => UploadedFile::fake()->image('new-id.jpg')];

    if ($failure === 'upload') {
        $selfie = UploadedFile::fake()->image('new-selfie.jpg');
        $files['selfie'] = new class($selfie->name, $selfie->tempFile) extends File
        {
            public function store($path = '', $options = [])
            {
                return false;
            }
        };
    } else {
        Shop::saving(fn (Shop $shop) => $shop->verification_status === Shop::PENDING ? false : null);
    }

    $this->post('/api/vendor/shop/verification', shopVerificationPayload($files), ['Accept' => 'application/json'])
        ->assertInternalServerError();

    expect($this->shop->refresh()->verification_status)->toBe(Shop::REJECTED);
    expect($this->shop->id_document)->toBe($paths['id_document']);
    expect($this->disk->allFiles())->toEqualCanonicalizing(array_values($paths));
})->with(['upload', 'save']);

test('shop creation respects the owner limit', function () {
    Sanctum::actingAs($this->vendor);
    $this->postJson('/api/vendor/shop', [
        'name' => 'Another Shop',
        'address' => '2 Sample Street',
        'city' => 'Ikeja',
        'state' => 'Lagos',
        'phone' => '08012345678',
    ])->assertUnprocessable()->assertJsonPath('message', 'You already have a shop.');
    expect(Shop::where('vendor_id', $this->vendor->id)->count())->toBe(1);
});
