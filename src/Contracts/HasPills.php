<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Contracts;

/**
 * Class-level display metadata a model exposes for representation in UI chrome
 * (pills/badges, navigation labels): a human label and a Filament color name.
 *
 * Icons are resolved separately via the ModelReferencePresenter icon resolver
 * to keep this contract decoupled from any specific icon source. Configure
 * `ModelReferencePresenter::resolveIconUsing()` in your ServiceProvider.
 */
interface HasPills
{
    public static function label(): string;

    public static function color(): string;
}
