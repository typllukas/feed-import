<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/** an optional Laravel rule prefix, then a delimited pattern whose last character is an unescaped $ */
const END_ANCHORED_REGEX_PATTERN = '{^(?:not_regex:|regex:)?(?:(?<delimiter>[/~#!%|@]).*(?<!\\\\)\$\k<delimiter>|\{.*(?<!\\\\)\$\})(?<modifiers>[a-zA-Z]*)$}sD';

// without D a final $ also matches before a trailing newline, so '/^[A-Z]{3}$/' accepts "CZK\n"
it('gives every regex anchored at its end the D modifier', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $scannedDirectories = array_map(fn (string $directory): string => "$projectRoot/$directory", ['app', 'bootstrap', 'config', 'database', 'routes', 'tests']);
    $regexesWithoutDModifier = [];
    foreach (Finder::create()->files()->name('*.php')->in($scannedDirectories) as $phpFile) {
        foreach (PhpToken::tokenize($phpFile->getContents()) as $token) {
            if ($token->id !== T_CONSTANT_ENCAPSED_STRING || preg_match(END_ANCHORED_REGEX_PATTERN, substr($token->text, 1, -1), $patternMatches) !== 1) {
                continue;
            }

            // with m the $ is meant to match at every line end, and D has no effect
            if (! str_contains($patternMatches['modifiers'], 'D') && ! str_contains($patternMatches['modifiers'], 'm')) {
                $regexesWithoutDModifier[] = sprintf('%s:%d %s', substr($phpFile->getPathname(), strlen($projectRoot) + 1), $token->line, $token->text);
            }
        }
    }

    expect($regexesWithoutDModifier)->toBe([]);
});
