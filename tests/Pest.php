<?php

declare(strict_types=1);

use Mmoollllee\FilamentModelLink\FilamentModelLink;
use Mmoollllee\FilamentModelLink\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

uses()->beforeEach(function (): void {
    FilamentModelLink::flush();
})->in(__DIR__);
