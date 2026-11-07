<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class FeedHeader implements ValidationRule
{
    public const array COLUMNS = ['sku', 'name', 'price', 'currency', 'stock'];

    /**
     * @param  resource  $feedStream
     */
    public static function describeMismatch($feedStream): ?string
    {
        $firstLine = fgets($feedStream);
        $headerLine = mb_scrub(trim(Str::chopStart($firstLine === false ? '' : $firstLine, "\u{FEFF}")), 'UTF-8');
        if (str_getcsv($headerLine, ';', '"', '') === self::COLUMNS) {
            return null;
        }

        return Str::limit(sprintf('Expected the header %s, got %s.', implode(';', self::COLUMNS), $headerLine), 1000);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ($feedStream = fopen($value->getRealPath(), 'r')) === false) {
            return;
        }

        try {
            $headerMismatch = self::describeMismatch($feedStream);
        } finally {
            fclose($feedStream);
        }

        if ($headerMismatch !== null) {
            $fail($headerMismatch);
        }
    }
}
