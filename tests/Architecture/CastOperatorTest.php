<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// a cast converts without checking: (int) 'abc' is 0 and (string) false is ''; config/ keeps Laravel's own casts of env()
it('uses no cast operator: input is validated, a known type converted with intval() or strval()', function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $scannedDirectories = array_map(fn (string $directory): string => "$projectRoot/$directory", ['app', 'bootstrap', 'database', 'routes', 'tests']);
    $castOperators = [];
    foreach (Finder::create()->files()->name('*.php')->in($scannedDirectories) as $phpFile) {
        foreach (PhpToken::tokenize($phpFile->getContents()) as $token) {
            if ($token->is([T_INT_CAST, T_DOUBLE_CAST, T_STRING_CAST, T_BOOL_CAST, T_ARRAY_CAST, T_OBJECT_CAST])) {
                $castOperators[] = sprintf('%s:%d %s', substr($phpFile->getPathname(), strlen($projectRoot) + 1), $token->line, $token->text);
            }
        }
    }

    expect($castOperators)->toBe([]);
});
