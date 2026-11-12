<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use LazilyRefreshDatabase;

    /** @var list<string|null> */
    protected array $connectionsToTransact = [null, 'price_history'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * migrate:fresh wipes only the default connection, and the price history migrations would find their tables.
     */
    protected function migrateDatabases(): void
    {
        $this->artisan('db:wipe', ['--database' => 'price_history']);
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
    }
}
