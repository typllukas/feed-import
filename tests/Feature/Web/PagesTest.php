<?php

declare(strict_types=1);

use App\Models\FeedImport;
use App\Models\Partner;
use App\Models\Product;
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

it('renders one import with the props its result component reads, varying on Accept', function (): void {
    $feedImport = FeedImport::factory()
        ->for(Partner::factory()->create(['name' => 'Acme Tools']))
        ->create(['failure_reason' => 'The "acme" feed is missing.']);

    $pageHtml = $this->actingAs(User::factory()->create())
        ->get(route('feed-imports.show', $feedImport))
        ->assertOk()
        ->assertHeader('Vary', 'Accept')
        ->assertSee('Acme Tools')
        ->getContent();
    expect($pageHtml)->toBeString()
        ->and(preg_match('/<div data-feed-import-result data-props="([^"]*)">/', $pageHtml, $propsMatch))->toBe(1);
    $mountProps = json_decode(html_entity_decode($propsMatch[1]), true, flags: JSON_THROW_ON_ERROR);

    expect($mountProps)->toMatchArray([
        'feedImportUrl' => route('feed-imports.show', $feedImport),
        'rejectedRowsUrl' => route('feed-imports.rejected-rows.index', $feedImport),
    ])
        ->and($mountProps['initialFeedImport'])->toHaveKeys(['status', 'total_row_count', 'imported_row_count', 'rejected_row_count', 'started_at', 'finished_at', 'failure_reason'])
        ->and($mountProps['initialFeedImport']['failure_reason'])->toBe('The "acme" feed is missing.');
});
