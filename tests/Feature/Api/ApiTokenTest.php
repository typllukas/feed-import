<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyApiToken;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;

// unknown ids: a route that looked its record up before the token would answer 404 and tell an outsider which ids exist
it('refuses every api route without a valid token, before looking anything up', function (string $method, string $uri, array $tokenHeader): void {
    $this->json($method, $uri, [], $tokenHeader)
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
})->with([
    ['GET', '/api/partners'],
    ['POST', '/api/partners'],
    ['GET', '/api/partners/999999'],
    ['PUT', '/api/partners/999999'],
    ['DELETE', '/api/partners/999999'],
    ['GET', '/api/partners/999999/feed-imports'],
    ['POST', '/api/partners/999999/feed-imports'],
    ['GET', '/api/partners/999999/products'],
    ['GET', '/api/feed-imports/999999'],
    ['GET', '/api/feed-imports/999999/rejected-rows'],
])->with([
    'no token' => [[]],
    'a wrong token' => [['X-Api-Token' => 'wrong-token']],
]);

it('rejects an empty token even when the configured one is empty', function (): void {
    config(['feed.api_token' => '']);

    $this->getJson('/api/partners', ['X-Api-Token' => ''])->assertUnauthorized();
});

it('puts the token check on every api route the router knows, including ones added later', function (): void {
    $apiRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn (RegisteredRoute $route): bool => str_starts_with($route->uri(), 'api/'));

    expect($apiRoutes)->not->toBeEmpty()
        ->and($apiRoutes->reject(fn (RegisteredRoute $route): bool => in_array(VerifyApiToken::class, $route->gatherMiddleware(), true))->map->uri()->values()->all())->toBe([]);
});
