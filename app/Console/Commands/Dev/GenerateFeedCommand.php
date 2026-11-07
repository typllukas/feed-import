<?php

declare(strict_types=1);

namespace App\Console\Commands\Dev;

use App\Rules\FeedHeader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use SplFileObject;

#[Signature('feed:generate {path : Where to write the CSV file} {--rows=100000 : Number of products} {--changed=0 : Percentage of products whose price differs from a run with --changed=0}')]
#[Description('Write a generated feed of the given size, for measuring an import at scale')]
final class GenerateFeedCommand extends Command
{
    public function handle(): int
    {
        $path = $this->argument('path');
        $rowCount = filter_var($this->option('rows'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $changedProductPercent = filter_var($this->option('changed'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
        if ($rowCount === false || $changedProductPercent === false) {
            $this->error('--rows must be a whole number of at least 1 and --changed a whole number between 0 and 100.');

            return self::FAILURE;
        }

        $feedFile = new SplFileObject($path, 'w');
        $feedFile->fwrite(implode(';', FeedHeader::COLUMNS)."\n");
        for ($rowNumber = 1; $rowNumber <= $rowCount; $rowNumber++) {
            $priceIncrement = $rowNumber % 100 < $changedProductPercent ? 1 : 0;
            $feedFile->fwrite(sprintf("SCALE-%07d;Product %d;%d.90;CZK;%d\n", $rowNumber, $rowNumber, 10 + $rowNumber % 5000 + $priceIncrement, $rowNumber % 200));
        }

        $this->line(sprintf('Wrote %d products to %s, %d %% of them with a changed price.', $rowCount, $path, $changedProductPercent));

        return self::SUCCESS;
    }
}
