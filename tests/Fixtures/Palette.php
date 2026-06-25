<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Tests\Fixtures;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Enum whose getColor() returns a shade-keyed array (like a Filament `Color::*`
 * constant) for one case and a plain palette-name string for another — to
 * exercise renderEnumOption's color handling.
 */
enum Palette: string implements HasColor, HasLabel
{
    case Shaded = 'shaded';
    case Named = 'named';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            // Mimics Color::Red — keyed by shade number, NOT a palette name.
            self::Shaded => [50 => '#fef2f2', 600 => '#dc2626', 950 => '#450a0a'],
            self::Named => 'success',
        };
    }
}
