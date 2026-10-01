<?php

declare(strict_types=1);

use Mmoollllee\FilamentModelLink\FilamentModelLink;
use Mmoollllee\FilamentModelLink\Tests\Feature\TableTestCase;
use Mmoollllee\FilamentModelLink\Tests\TestCase;

uses(TestCase::class)->in('Unit');
uses(TableTestCase::class)->in('Feature');

uses()->beforeEach(function (): void {
    FilamentModelLink::flush();
})->in(__DIR__);
