<?php

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->productData = [
        'name' => 'Brake Pads',
        'brand' => 'Acme',
        'category' => 'Brakes',
        'description' => 'Ceramic compound',
        'initial_price' => 4000,
        'stock' => 5,
    ];
});

it('applies shop visibility to product lists, details and categories', function (bool $strict) {
    config(['marketplace.require_verified_to_list' => $strict]);
    $vendor = User::factory()->create();
    $approved = $this->createShop($vendor, ['verification_status' => Shop::APPROVED, 'is_verified' => true]);
    $pending = $this->createShop($vendor, ['verification_status' => Shop::PENDING]);
    $suspended = $this->createShop($vendor, ['verification_status' => Shop::SUSPENDED, 'is_verified' => true]);
    $deleted = $this->createShop($vendor, ['verification_status' => Shop::APPROVED, 'is_verified' => true]);

    $products = collect([
        'Brakes' => $approved,
        'Cooling' => $pending,
        'Steering' => $suspended,
        'Engine' => $deleted,
    ])->map(fn (Shop $shop, string $category) => $shop->products()->create(
        array_replace($this->productData, ['category' => $category]),
    ));
    $deleted->delete();

    $expected = $strict ? [$products['Brakes']->id] : [$products['Cooling']->id, $products['Brakes']->id];
    $response = $this->getJson('/api/products')->assertOk();
    expect(array_column($response->json('data'), 'id'))->toBe($expected);

    $this->getJson('/api/products/categories')->assertOk()
        ->assertExactJson(['categories' => $strict ? ['Brakes'] : ['Brakes', 'Cooling']]);
    foreach ($products as $product) {
        $this->getJson("/api/products/{$product->id}")->assertStatus(in_array($product->id, $expected, true) ? 200 : 404);
    }
})->with(['permissive' => [false], 'verified only' => [true]]);

it('searches across product text fields without letting matches from suspended shops through', function (string $query) {
    config(['marketplace.require_verified_to_list' => false]);
    $vendor = User::factory()->create();
    $shop = $this->createShop($vendor, ['id_document' => 'kyc/internal.pdf']);
    $product = $shop->products()->create($this->productData);
    $shop->products()->create(array_replace($this->productData, [
        'name' => 'Timing Belt', 'brand' => 'Other', 'description' => 'Rubber',
    ]));
    $this->createShop($vendor, ['verification_status' => Shop::SUSPENDED])->products()->create($this->productData);

    $this->getJson('/api/products?q='.urlencode($query))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $product->id)
        ->assertJsonPath('data.0.verified', false)
        ->assertJsonMissingPath('data.0.kyc')
        ->assertJsonMissingPath('data.0.id_document');
})->with(['name' => ['bRaKe'], 'brand' => ['ACME'], 'description' => ['cERAMIC']]);

it('marks public products as owned only for the vendor authenticated by the bearer token', function (?string $viewer, bool $isOwn) {
    config(['marketplace.require_verified_to_list' => false]);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $product = $this->createShop($owner)->products()->create($this->productData);

    if ($viewer) {
        $this->withToken(($viewer === 'owner' ? $owner : $other)->createToken('test-browser')->plainTextToken);
    }

    $this->getJson("/api/products/{$product->id}")->assertOk()->assertJsonPath('data.is_own', $isOwn);
})->with(['guest' => [null, false], 'owner' => ['owner', true], 'another vendor' => ['other', false]]);

it('stores and replaces product images on the configured disk', function () {
    config([
        'marketplace.public_disk' => 'catalogue',
        'filesystems.disks.catalogue' => ['driver' => 'local'],
    ]);
    Storage::fake('catalogue');
    Storage::fake('public');
    $vendor = User::factory()->create();
    $this->createShop($vendor);
    Sanctum::actingAs($vendor);

    $id = $this->postJson('/api/products', $this->productData + [
        'image' => UploadedFile::fake()->image('original.jpg'),
    ])->assertCreated()->json('data.id');
    $product = Product::findOrFail($id);
    $original = $product->image;
    Storage::disk('catalogue')->assertExists($original);

    $response = $this->post("/api/products/$id", [
        '_method' => 'PUT',
        'image' => UploadedFile::fake()->image('new.jpg'),
    ], ['Accept' => 'application/json'])->assertOk();

    $replacement = $product->fresh()->image;
    Storage::disk('catalogue')->assertExists($replacement);
    Storage::disk('catalogue')->assertMissing($original);
    Storage::disk('public')->assertMissing([$original, $replacement]);
    $response->assertJsonPath('data.product_image', asset("storage/$replacement"));
});

it('uses the storage driver URL for nonlocal product images', function () {
    config([
        'marketplace.public_disk' => 'catalogue',
        'marketplace.require_verified_to_list' => false,
        'filesystems.disks.catalogue' => ['driver' => 's3'],
    ]);
    $disk = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
    $disk->shouldReceive('url')->once()->with('product_images/photo.jpg')
        ->andReturn('https://cdn.example.test/product_images/photo.jpg');
    Storage::shouldReceive('disk')->with('catalogue')->andReturn($disk);

    $product = $this->createShop(User::factory()->create())->products()->create($this->productData + [
        'image' => 'product_images/photo.jpg',
    ]);

    $this->getJson("/api/products/{$product->id}")->assertOk()
        ->assertJsonPath('data.product_image', 'https://cdn.example.test/product_images/photo.jpg');
});
