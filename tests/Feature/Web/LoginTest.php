<?php

declare(strict_types=1);

use App\Models\FeedImport;
use App\Models\User;

it('prefills the demo account only in the local environment', function (): void {
    $this->get('/login')->assertOk()->assertDontSee('demo@feed-import.test');

    $this->app['env'] = 'local';

    $this->get('/login')->assertOk()->assertSee('demo@feed-import.test')->assertSee('feed-import-demo');
});

it('rejects a wrong password and keeps the e-mail', function (): void {
    $user = User::factory()->create(['password' => 'password']);

    $this->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email')
        ->assertSessionHasInput('email', $user->email);

    $this->assertGuest();
});

it('shows each login error under its input and links the two', function (): void {
    $this->withViewErrors(['email' => 'These credentials do not match our records.', 'password' => 'The password field is required.'])
        ->view('auth.login')
        ->assertSeeInOrder([
            'aria-invalid="true" aria-describedby="email-error"',
            'aria-invalid="true" aria-describedby="password-error"',
            'id="email-error">These credentials do not match our records.',
            'id="password-error">The password field is required.',
        ], false);
});

it('logs in and redirects to the partners page', function (): void {
    $user = User::factory()->create(['password' => 'password']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/partners');

    $this->assertAuthenticatedAs($user);
});

it('returns a guest to the page they asked for after the login', function (): void {
    $user = User::factory()->create(['password' => 'password']);
    $feedImportPage = route('feed-imports.show', FeedImport::factory()->create());
    $this->get($feedImportPage)->assertRedirect('/login');

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect($feedImportPage);
});

it('sends a logged-in user away from the login form', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/login')
        ->assertRedirect('/');
});

it('refuses a sixth login attempt within a minute', function (): void {
    $user = User::factory()->create(['password' => 'password']);

    foreach (range(1, 5) as $attemptNumber) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertRedirect();
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();
    $this->assertGuest();
});

it('logs in with the demo account the seeder creates', function (): void {
    $this->seed();

    $this->post('/login', ['email' => 'demo@feed-import.test', 'password' => 'feed-import-demo'])
        ->assertRedirect('/partners');

    $this->assertAuthenticated();
});

it('logs out', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});
