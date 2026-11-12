<?php

declare(strict_types=1);

use App\Models\FeedImport;
use App\Models\RejectedRow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;

it('returns the import as JSON to a JSON request and keys caches on the Accept header', function (): void {
    $feedImport = FeedImport::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('feed-imports.show', $feedImport))
        ->assertOk()
        ->assertHeader('Vary', 'Accept')
        ->assertJsonPath('data.id', $feedImport->id)
        ->assertJsonPath('data.status', $feedImport->status->value);
});

it('answers with the fields and formats of the contract example the Vue component parses', function (): void {
    $contractExample = File::json(resource_path('js/api/feedImport.example.json'), JSON_THROW_ON_ERROR);
    $feedImport = FeedImport::factory()->create([
        'id' => 12,
        'total_row_count' => 27,
        'imported_row_count' => 1,
        'rejected_row_count' => 26,
        'started_at' => '2026-11-11 10:00:00',
        'finished_at' => '2026-11-11 10:00:02',
    ]);
    RejectedRow::factory()->for($feedImport)->count(25)
        ->sequence(fn (Sequence $sequence): array => ['line_number' => $sequence->index + 2])->create();
    RejectedRow::factory()->for($feedImport)->create([
        'id' => 901,
        'line_number' => 27,
        'row' => ['sku' => 'ACM-006', 'name' => 'Tool 006', 'price' => '-12.90', 'currency' => 'CZK', 'stock' => '42'],
    ]);
    $this->actingAs(User::factory()->create());
    URL::forceRootUrl('http://localhost');

    $this->getJson(route('feed-imports.show', $feedImport))
        ->assertOk()
        ->assertJson(Arr::array($contractExample, 'feedImport'), strict: true);
    $this->getJson(route('feed-imports.rejected-rows.index', [$feedImport, 'page' => 2]))
        ->assertOk()
        ->assertJson(Arr::array($contractExample, 'rejectedRowPage'), strict: true);
});

it('answers a guest JSON request with 401', function (): void {
    $feedImport = FeedImport::factory()->create();

    $this->getJson(route('feed-imports.show', $feedImport))->assertUnauthorized();
    $this->getJson(route('feed-imports.rejected-rows.index', $feedImport))->assertUnauthorized();
});

it('pages the rejected rows 25 at a time in line order with the original line', function (): void {
    $feedImport = FeedImport::factory()->create();
    RejectedRow::factory()->for($feedImport)->count(25)
        ->sequence(fn (Sequence $sequence): array => ['line_number' => 27 - $sequence->index])->create();
    RejectedRow::factory()->for($feedImport)->create([
        'line_number' => 2,
        'row' => ['sku' => 'ACM-001', 'name' => 'Hammer', 'price' => '-1', 'currency' => 'CZK', 'stock' => '4'],
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('feed-imports.rejected-rows.index', $feedImport))
        ->assertOk()
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('data.0.line_number', 2)
        ->assertJsonPath('data.0.feed_line', 'ACM-001;Hammer;-1;CZK;4')
        ->assertJsonPath('links.next', route('feed-imports.rejected-rows.index', $feedImport).'?page=2');
});

it('lists only the rejected rows of the import in the url', function (): void {
    $feedImport = FeedImport::factory()->create();
    $rejectedRow = RejectedRow::factory()->for($feedImport)->create();
    RejectedRow::factory()->for(FeedImport::factory())->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('feed-imports.rejected-rows.index', $feedImport))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $rejectedRow->id);
});
