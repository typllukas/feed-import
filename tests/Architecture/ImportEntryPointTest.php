<?php

declare(strict_types=1);

use App\Jobs\ImportFeed;
use App\Providers\AppServiceProvider;
use App\Services\FeedImportStarter;

// AppServiceProvider only routes the job to its queue
arch('the import job is queued only from the one entry point')
    ->expect(ImportFeed::class)
    ->toOnlyBeUsedIn([FeedImportStarter::class, AppServiceProvider::class]);
