<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FeedImportStatus;
use App\Exceptions\FeedFileNotStoredException;
use App\Jobs\ImportFeed;
use App\Models\FeedImport;
use App\Models\Partner;
use Illuminate\Container\Attributes\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The one place that queues ImportFeed; ImportEntryPointTest keeps it so.
 */
final readonly class FeedImportStarter
{
    public function __construct(#[Storage('feeds')] private Filesystem $feedDisk) {}

    public function start(Partner $partner, File|UploadedFile $feedFile): FeedImport
    {
        $storedPath = $this->feedDisk->putFile('feeds', $feedFile);
        if ($storedPath === false) {
            throw new FeedFileNotStoredException(sprintf('Feed file for partner %s could not be stored.', $partner->code));
        }

        // the database queue writes the job in this transaction: no pending import without its job
        $feedImport = DB::transaction(function () use ($partner, $storedPath): FeedImport {
            $feedImport = $partner->feedImports()->create([
                'status' => FeedImportStatus::Pending,
                'file_path' => $storedPath,
            ]);
            ImportFeed::dispatch($feedImport);

            return $feedImport;
        });

        // the counters' zero defaults come from the database
        return $feedImport->refresh();
    }
}
