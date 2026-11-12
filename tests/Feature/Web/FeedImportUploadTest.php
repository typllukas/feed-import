<?php

declare(strict_types=1);

use App\Jobs\ImportFeed;
use App\Models\FeedImport;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('redirects a guest from the upload form and the upload to the login page', function (): void {
    $partner = Partner::factory()->create();
    $this->get(route('partners.feed-imports.create', $partner))->assertRedirect('/login');
    $this->post(route('partners.feed-imports.store', $partner))->assertRedirect('/login');
});

it('links each partner to its upload form', function (): void {
    $partners = Partner::factory()->count(2)->create();
    $response = $this->actingAs(User::factory()->create())->get('/partners');

    foreach ($partners as $partner) {
        $response->assertSee(route('partners.feed-imports.create', $partner));
    }
});

it('shows the upload form for one partner', function (): void {
    $partner = Partner::factory()->create(['name' => 'Acme Tools']);
    $this->actingAs(User::factory()->create())->get(route('partners.feed-imports.create', $partner))
        ->assertOk()->assertSee('Acme Tools')
        ->assertSee(route('partners.feed-imports.store', $partner))
        ->assertSee('enctype="multipart/form-data"', false)
        ->assertSee('Feed file (CSV, up to 10 MB)');
});

it('offers the sample feed only in the local environment', function (): void {
    $partner = Partner::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('partners.feed-imports.create', $partner))->assertOk()
        ->assertDontSee('data-sample-feed', false)->assertDontSee('Sample feed already chosen');
    $this->get('/partners')->assertDontSee('Upload feed to watch a new one finish');

    $this->app['env'] = 'local';

    $this->get(route('partners.feed-imports.create', $partner))->assertOk()
        ->assertSee('data-sample-feed="sku;name;price;currency;stock', false)->assertSee('Sample feed already chosen');
    $this->get('/partners')->assertSee('Upload feed to watch a new one finish');
});

it('stores the uploaded feed and redirects to the new import', function (): void {
    Storage::fake('feeds');
    Queue::fake();
    $partner = Partner::factory()->create();
    $feedFile = UploadedFile::fake()->createWithContent('acme.csv', "sku;name;price;currency;stock\nACM-001;Hammer;129.90;CZK;4\n");

    $this->actingAs(User::factory()->create())
        ->post(route('partners.feed-imports.store', $partner), ['file' => $feedFile])
        ->assertRedirect(route('feed-imports.show', FeedImport::sole()));

    Queue::assertPushedOn('feeds', ImportFeed::class);
    Storage::disk('feeds')->assertExists(FeedImport::sole()->file_path);
});

it('accepts a feed whose product names carry HTML', function (): void {
    Storage::fake('feeds');
    Queue::fake();
    $partner = Partner::factory()->create();
    $fakeFeedFile = UploadedFile::fake()->createWithContent('acme.csv', "sku;name;price;currency;stock\nACM-001;Hammer <a href=\"https://example.com\">info</a>;129.90;CZK;4\n");
    // a fake file reports the type of its name; this one is sniffed from its content, as an upload is
    $feedFile = new UploadedFile($fakeFeedFile->getRealPath(), 'acme.csv', test: true);

    $this->actingAs(User::factory()->create())
        ->post(route('partners.feed-imports.store', $partner), ['file' => $feedFile])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('feed-imports.show', FeedImport::sole()));
});

it('sends a wrong header back to the form and stores nothing', function (): void {
    Storage::fake('feeds');
    Queue::fake();
    $partner = Partner::factory()->create();
    $createUrl = route('partners.feed-imports.create', $partner);
    $feedFile = UploadedFile::fake()->createWithContent('acme.csv', "name;sku;price;currency;stock\nHammer;ACM-001;129.90;CZK;4\n");
    $this->actingAs(User::factory()->create());

    $this->from($createUrl)->post(route('partners.feed-imports.store', $partner), ['file' => $feedFile])
        ->assertRedirect($createUrl)
        ->assertSessionHasErrors(['file' => 'Expected the header sku;name;price;currency;stock, got name;sku;price;currency;stock.']);

    expect(FeedImport::count())->toBe(0)
        ->and(Storage::disk('feeds')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('shows the file error under the input and links the two', function (): void {
    $this->actingAs(User::factory()->create())
        ->withViewErrors(['file' => 'Expected the header sku;name;price;currency;stock, got name;sku;price;currency;stock.'])
        ->view('feed-imports.create', ['partner' => Partner::factory()->create(), 'sampleFeed' => null])
        ->assertSeeInOrder(['aria-invalid="true" aria-describedby="file-error"', 'id="file-error">Expected the header sku;name;price;currency;stock, got name;sku;price;currency;stock.'], false);
});
