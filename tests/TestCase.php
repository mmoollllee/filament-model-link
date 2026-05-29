<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Tests;

use Mmoollllee\FilamentModelLink\FilamentModelLinkServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            FilamentModelLinkServiceProvider::class,
        ];
    }
}
