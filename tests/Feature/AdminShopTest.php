<?php

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('requires authentication and the correct role for shop operations', function () {
    $this->getJson('/api/admin/shops')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    $this->getJson('/api/vendor/shop')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create(['role' => 'vendor']));
    $this->getJson('/api/admin/shops/1/documents/id_document')->assertForbidden();
    $this->putJson('/api/admin/shops/1/verification', ['decision' => 'approve'])->assertForbidden();

    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    $this->postJson('/api/vendor/shop/verification')->assertForbidden();
    $this->getJson('/api/vendor/banks')->assertForbidden();
});

it('filters the admin review queue by status and vendor name and paginates oldest submissions first', function () {
    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    $vendor = User::factory()->create(['surname' => 'Okafor']);
    $newer = $this->createShop($vendor, ['verification_status' => Shop::PENDING, 'submitted_at' => now()]);
    $older = $this->createShop($vendor, ['verification_status' => Shop::PENDING, 'submitted_at' => now()->subDay()]);
    $this->createShop($vendor, ['verification_status' => Shop::APPROVED]);
    $this->createShop(User::factory()->create(['surname' => 'Nwosu']), ['verification_status' => Shop::PENDING]);

    $url = '/api/admin/shops?status=pending&q=OKAFOR&per_page=1';
    $this->getJson($url)
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.id', $older->id)
        ->assertJsonPath('data.0.vendor.id', $vendor->id)
        ->assertJsonPath('data.0.products_count', 0);
    $this->getJson($url.'&page=2')->assertOk()->assertJsonPath('data.0.id', $newer->id);
    $this->getJson('/api/admin/shops?status=unknown&per_page=51')->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'per_page']);
});

it('streams available private documents without caching and records the admin view', function () {
    config(['marketplace.private_disk' => 'local']);
    Storage::fake('local');
    Log::spy();
    $admin = User::factory()->create(['role' => 'admin']);
    Sanctum::actingAs($admin);
    $shop = $this->createShop(User::factory()->create(), [
        'id_document' => 'kyc/id.pdf',
        'selfie' => 'kyc/missing.jpg',
    ]);
    Storage::disk('local')->put('kyc/id.pdf', 'synthetic document');

    $url = "/api/admin/shops/{$shop->id}/documents";
    $response = $this->getJson("$url/id_document")->assertOk()->assertStreamed();
    expect($response->streamedContent())->toBe('synthetic document')
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->getJson("$url/selfie")->assertNotFound();
    $this->getJson("$url/address_proof")->assertNotFound();
    $this->getJson("$url/account_number")->assertNotFound();

    Log::shouldHaveReceived('info')->once()->with('KYC document viewed', [
        'admin_id' => $admin->id,
        'shop_id' => $shop->id,
        'document' => 'id_document',
    ]);
});

it('updates verification and reviewer audit fields for allowed decisions', function (string $before, string $decision, string $after) {
    $admin = User::factory()->create(['role' => 'admin']);
    Sanctum::actingAs($admin);
    $approved = $after === Shop::APPROVED;
    $shop = $this->createShop(User::factory()->create(), [
        'verification_status' => $before,
        'is_verified' => $before === Shop::APPROVED,
        'verified_by' => $before === Shop::APPROVED ? $admin->id : null,
        'verified_at' => $before === Shop::APPROVED ? now()->subDay() : null,
        'rejection_reason' => $before === Shop::SUSPENDED ? 'Previous suspension.' : null,
    ]);
    $reason = 'Documents require correction.';
    $payload = $approved ? ['decision' => $decision] : ['decision' => $decision, 'reason' => $reason];

    $this->putJson("/api/admin/shops/{$shop->id}/verification", $payload)
        ->assertOk()
        ->assertJsonPath('data.verification_status', $after)
        ->assertJsonPath('data.is_verified', $approved)
        ->assertJsonPath('data.rejection_reason', $approved ? null : $reason);

    $shop->refresh();
    expect($shop->reviewed_by)->toBe($admin->id)
        ->and($shop->reviewed_at)->not->toBeNull()
        ->and($shop->verified_by)->toBe($approved ? $admin->id : null)
        ->and($shop->verified_at !== null)->toBe($approved);
})->with([
    'approve new submission' => [Shop::PENDING, 'approve', Shop::APPROVED],
    'reinstate suspended shop' => [Shop::SUSPENDED, 'approve', Shop::APPROVED],
    'reject new submission' => [Shop::PENDING, 'reject', Shop::REJECTED],
    'suspend an approved shop' => [Shop::APPROVED, 'suspend', Shop::SUSPENDED],
    'suspend a pending shop' => [Shop::PENDING, 'suspend', Shop::SUSPENDED],
    'suspend a rejected shop' => [Shop::REJECTED, 'suspend', Shop::SUSPENDED],
    'suspend an unsubmitted shop' => [Shop::UNSUBMITTED, 'suspend', Shop::SUSPENDED],
]);

it('refuses disallowed transitions without writing review audit fields', function (string $before, string $decision) {
    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    $shop = $this->createShop(User::factory()->create(), ['verification_status' => $before]);

    $this->putJson("/api/admin/shops/{$shop->id}/verification", [
        'decision' => $decision,
        'reason' => 'Documents require correction.',
    ])->assertUnprocessable();

    expect($shop->fresh()->verification_status)->toBe($before)
        ->and($shop->fresh()->reviewed_by)->toBeNull()
        ->and($shop->fresh()->reviewed_at)->toBeNull();
})->with([
    [Shop::UNSUBMITTED, 'approve'],
    [Shop::APPROVED, 'approve'],
    [Shop::REJECTED, 'approve'],
    [Shop::UNSUBMITTED, 'reject'],
    [Shop::APPROVED, 'reject'],
    [Shop::REJECTED, 'reject'],
    [Shop::SUSPENDED, 'reject'],
    [Shop::SUSPENDED, 'suspend'],
]);

it('validates review decisions and requires a meaningful reason for rejection or suspension', function () {
    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    $shop = $this->createShop(User::factory()->create(), ['verification_status' => Shop::PENDING]);
    $url = "/api/admin/shops/{$shop->id}/verification";

    $this->putJson($url, ['decision' => 'publish'])->assertUnprocessable()->assertJsonValidationErrors('decision');
    $this->putJson($url, ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->putJson($url, ['decision' => 'suspend', 'reason' => 'too short'])->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
    $this->putJson('/api/admin/shops/999/verification', ['decision' => 'approve'])->assertNotFound();
    expect($shop->fresh()->verification_status)->toBe(Shop::PENDING);
});
