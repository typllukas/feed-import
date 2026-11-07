<?php

declare(strict_types=1);

use App\Exceptions\FeedFileNotStoredException;
use App\Models\FeedImport;
use App\Models\Partner;
use App\Services\FeedImportStarter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('creates no import when the feed disk refuses the file', function (): void {
    $feedDisk = Mockery::mock(Filesystem::class);
    $feedDisk->shouldReceive('putFile')->once()->andReturn(false);
    Storage::set('feeds', $feedDisk);
    $feed = UploadedFile::fake()->createWithContent('acme.csv', "sku;name;price;currency;stock\n");

    expect(fn () => app(FeedImportStarter::class)->start(Partner::factory()->create(), $feed))
        ->toThrow(FeedFileNotStoredException::class)
        ->and(FeedImport::count())->toBe(0);
});
