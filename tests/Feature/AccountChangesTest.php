<?php

use App\Models\AccountVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('revokes only the bearer token used to log out', function () {
    $user = User::factory()->create();
    $current = $user->createToken('current device');
    $other = $user->createToken('other device');

    $this->postJson('/api/logout')->assertUnauthorized();
    $this->withToken($current->plainTextToken)->postJson('/api/logout')->assertOk();

    $this->assertModelMissing($current->accessToken);
    $this->assertModelExists($other->accessToken);
});

it('returns public user fields on login without exposing internal account fields', function () {
    $user = User::factory()->create(['email' => 'ada@example.test', 'password' => 'test-password']);

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-password'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonPath('user.role', $user->role)
        ->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.remember_token')
        ->assertJsonMissingPath('user.deleted_at');
});

it('reports login errors while controlling client visibility of internal details', function (bool $debug) {
    config(['app.debug' => $debug]);
    $user = User::factory()->create(['email' => 'ada@example.test']);
    $detail = 'Synthetic internal authentication failure';
    Hash::shouldReceive('check')->once()->andThrow(new RuntimeException($detail));

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-password'])
        ->assertInternalServerError()
        ->assertJsonPath('errors', $debug ? $detail : null);
})->with(['production response' => [false], 'debug response' => [true]]);

it('limits verification submissions per normalized email address across clients', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/verify', ['email' => 'Ada@example.test'])->assertUnprocessable();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11']);
    $this->postJson('/api/verify', ['email' => 'ada@example.test'])->assertTooManyRequests();
    $this->postJson('/api/verify', ['email' => 'another@example.test'])->assertUnprocessable();
});

it('bootstraps a verified admin and leaves an existing account unchanged when repeated', function () {
    $this->artisan('app:create-admin', [
        'email' => 'ADMIN@example.test', '--password' => 'initial-password', '--phone' => '08012345678',
    ])->assertSuccessful();

    $admin = User::where('email', 'admin@example.test')->sole();
    expect($admin->role)->toBe('admin')
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('initial-password', $admin->password))->toBeTrue();

    $this->artisan('app:create-admin', [
        'email' => 'admin@example.test', '--password' => 'replacement-password',
    ])->assertSuccessful();
    expect($admin->fresh()->password)->toBe($admin->password)
        ->and(User::where('email', 'admin@example.test')->count())->toBe(1);
});

it('rejects invalid initial admin credentials without creating a user', function (string $email, string $password) {
    $this->artisan('app:create-admin', ['email' => $email, '--password' => $password])->assertFailed();
    $this->assertDatabaseCount('users', 0);
})->with([
    'invalid email' => ['invalid-address', 'initial-password'],
    'short password' => ['admin@example.test', '12345678901'],
]);

it('returns a JSON error for an unknown API route even without an Accept header', function () {
    $this->get('/api/unknown-test-route')->assertNotFound()->assertHeader('Content-Type', 'application/json');
});

it('uses the public user resource during registration and verification', function () {
    $email = 'new-user@example.test';
    $this->postJson('/api/register', [
        'firstname' => 'Ada',
        'surname' => 'Okafor',
        'email' => $email,
        'phone' => '08012345678',
        'password' => 'Strong1!pass',
        'password_confirmation' => 'Strong1!pass',
    ])->assertCreated()
        ->assertJsonPath('user.email', $email)
        ->assertJsonMissingPath('user.deleted_at');

    $code = AccountVerification::where('email', $email)->sole()->token;
    expect((string) $code)->toMatch('/^[1-9][0-9]{5}$/');

    $this->postJson('/api/verify', ['email' => $email, 'token' => (string) $code])
        ->assertOk()
        ->assertJsonPath('user.email', $email)
        ->assertJsonMissingPath('user.deleted_at');
    expect(User::where('email', $email)->sole()->email_verified_at)->not->toBeNull();
});

it('rolls back an admin invite and hides transport details when delivery fails', function () {
    config(['app.debug' => false]);
    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    Mail::shouldReceive('send')->once()
        ->andThrow(new RuntimeException('Synthetic mail failure'));

    $this->postJson('/api/admin/invites', ['email' => 'invitee@example.test'])
        ->assertInternalServerError()
        ->assertJsonPath('errors', null);
    $this->assertDatabaseCount('admin_invites', 0);
});
