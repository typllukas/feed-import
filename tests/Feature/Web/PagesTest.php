<?php

declare(strict_types=1);

use App\Models\FeedImport;
use App\Models\Partner;
use App\Models\Product;
use App\Models\RejectedRow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;

it('redirects guests to the login page', function (): void {
    $this->get('/partners')->assertRedirect('/login');
    $this->get(route('feed-imports.show', FeedImport::factory()->create()))->assertRedirect('/login');
});

it('redirects the root to the partners page', function (): void {
    $this->get('/')->assertRedirect('/partners');
});

it('shows partners with product counts and the five most recent imports of each', function (): void {
    $partner = Partner::factory()
        ->has(Product::factory()->count(3))
        ->has(FeedImport::factory()->count(6)->sequence(fn (Sequence $sequence): array => ['created_at' => now()->subDays($sequence->index + 1)]))
        ->create(['name' => 'Acme Tools']);
    FeedImport::factory()->for($partner)->failed()->create(['created_at' => now(), 'failure_reason' => 'The disk is full.']);
    // the newest import overall, so a limit of 5 across all partners would push out one of Acme's
    $otherPartnerImport = FeedImport::factory()->for(Partner::factory()->create(['name' => 'Globex Parts']))->create(['created_at' => now()->addMinute()]);
    [$recentImports, $olderImports] = $partner->feedImports()->recent()->get()->partition(fn (FeedImport $feedImport, int $position): bool => $position < 5);
    expect($olderImports)->toHaveCount(2);

    $response = $this->actingAs(User::factory()->create())
        ->get('/partners')
        ->assertOk()
        ->assertSeeInOrder(['Acme Tools', '<td class="number">3</td>'], false)
        ->assertSee('The disk is full.')
        ->assertSee(route('feed-imports.show', $otherPartnerImport));
    foreach ($recentImports as $recentImport) {
        $response->assertSee(route('feed-imports.show', $recentImport));
    }
    foreach ($olderImports as $olderImport) {
        $response->assertDontSee(route('feed-imports.show', $olderImport));
    }
});

it('shows one import with its rejected rows', function (): void {
    $feedImport = FeedImport::factory()
        ->for(Partner::factory()->create(['name' => 'Acme Tools']))
        ->has(RejectedRow::factory()->state([
            'line_number' => 7,
            'message' => 'The price field must be at least 0.',
            'row' => ['sku' => 'ACM-007', 'name' => 'Wrench set', 'price' => '-12.90', 'currency' => 'CZK', 'stock' => '4'],
        ]))
        ->create();

    $this->actingAs(User::factory()->create())
        ->get(route('feed-imports.show', $feedImport))
        ->assertOk()
        ->assertSee('Acme Tools')
        ->assertSee($feedImport->status->value)
        ->assertSeeInOrder(['<td>7</td>', 'The price field must be at least 0.', '-12.90'], false);
});
