<?php

declare(strict_types=1);

use App\Models\Partner;
use App\Models\Product;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->withHeader('X-Api-Token', Config::string('feed.api_token'));
});

it('lists partners with their product counts', function (): void {
    Partner::factory()->has(Product::factory()->count(2))->create(['name' => 'Acme Tools', 'code' => 'acme']);

    $this->getJson('/api/partners')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'acme')
        ->assertJsonPath('data.0.products_count', 2);
});

it('pages partners by name with the id as a tiebreaker, since names repeat', function (): void {
    Partner::factory()->count(2)->create(['name' => 'Acme']);
    $partnerQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$partnerQueries): void {
        if (str_starts_with($query->sql, 'select `partners`.*')) {
            $partnerQueries[] = $query->sql;
        }
    });

    $this->getJson('/api/partners')->assertOk();

    expect($partnerQueries)->toHaveCount(1)
        ->and($partnerQueries[0])->toContain('order by `name` asc, `id` asc');
});

it('validates a new partner', function (): void {
    $this->postJson('/api/partners', ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'code']);
});

it('rejects a partner code that is taken, too long or not usable in a Kafka key', function (string $code): void {
    Partner::factory()->create(['code' => 'acme']);

    $this->postJson('/api/partners', ['name' => 'Globex Parts', 'code' => $code])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['code']);
})->with(['acme', str_repeat('a', 33), 'acme:tools']);

it('answers a validation error in JSON even when the client does not ask for it', function (): void {
    $this->post('/api/partners', ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('shows one partner with its product count', function (): void {
    $partner = Partner::factory()->has(Product::factory()->count(2))->create();

    $this->getJson("/api/partners/{$partner->id}")
        ->assertOk()
        ->assertJsonPath('data.code', $partner->code)
        ->assertJsonPath('data.products_count', 2);
});

it('creates a partner', function (): void {
    $this->postJson('/api/partners', ['name' => 'Globex Parts', 'code' => 'globex'])
        ->assertCreated()
        ->assertJsonPath('data.code', 'globex')
        ->assertJsonPath('data.products_count', 0);

    $this->assertDatabaseHas('partners', ['name' => 'Globex Parts', 'code' => 'globex']);
});

it('updates a partner', function (): void {
    $partner = Partner::factory()->has(Product::factory())->create();

    $this->patchJson("/api/partners/{$partner->id}", ['name' => 'Globex Parts Inc'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Globex Parts Inc')
        ->assertJsonPath('data.products_count', 1);

    expect($partner->refresh()->name)->toBe('Globex Parts Inc');
});

it('accepts the partner its own code on update', function (): void {
    $partner = Partner::factory()->create();

    $this->putJson("/api/partners/{$partner->id}", ['name' => $partner->name, 'code' => $partner->code])
        ->assertOk();
});

it('rejects the code of another partner on update', function (): void {
    $partner = Partner::factory()->create();
    $otherPartner = Partner::factory()->create();

    $this->patchJson("/api/partners/{$partner->id}", ['code' => $otherPartner->code])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['code']);
});

it('deletes a partner', function (): void {
    $partner = Partner::factory()->create();

    $this->deleteJson("/api/partners/{$partner->id}")
        ->assertNoContent();

    $this->assertModelMissing($partner);
});
