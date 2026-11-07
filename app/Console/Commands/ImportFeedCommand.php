<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Partner;
use App\Services\FeedImportStarter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\File;

#[Signature('feed:import {partner_code : Partner code, e.g. acme} {path : Path to the CSV file}')]
#[Description('Queue a feed import for a partner from a local CSV file')]
final class ImportFeedCommand extends Command
{
    public function handle(FeedImportStarter $feedImportStarter): int
    {
        $partnerCode = $this->argument('partner_code');
        $path = $this->argument('path');

        $partner = Partner::firstWhere('code', $partnerCode);
        if ($partner === null) {
            $this->error(sprintf('Unknown partner code %s.', $partnerCode));

            return self::FAILURE;
        }

        if (! is_file($path)) {
            $this->error(sprintf('File %s does not exist.', $path));

            return self::FAILURE;
        }

        $feedImport = $feedImportStarter->start($partner, new File($path));

        $this->line(sprintf('Queued feed import #%d for %s.', $feedImport->id, $partner->code));

        return self::SUCCESS;
    }
}
