<?php

declare(strict_types=1);

use Brain\Monkey;

// Common setup/teardown for all Unit tests: initialise Brain\Monkey so
// WordPress function stubs work, and close Mockery after each test.
uses()
    ->beforeEach(function (): void {
        Monkey\setUp();

        // apply_filters: just return the default value (2nd arg).
        Monkey\Functions\when('apply_filters')->returnArg(2);

        // wp_mkdir_p: actually create the directory so Store filesystem ops work in tests.
        Monkey\Functions\when('wp_mkdir_p')->alias(function (string $path): bool {
            return is_dir($path) || mkdir($path, 0755, true);
        });
    })
    ->afterEach(function (): void {
        Monkey\tearDown();
        Mockery::close();
    })
    ->in('Unit');
