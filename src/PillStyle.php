<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink;

use BackedEnum;

/**
 * How one RECORD looks as a pill, where the class-level defaults
 * (`HasPills::color()`, the icon resolver) are not enough — a customer that
 * wears its own favicon and brand color, say.
 *
 * Every field is optional; a missing one falls back to the class-level value.
 * Return it from the resolver registered with
 * `FilamentModelLink::configure()->resolveStyleUsing()`:
 *
 *     FilamentModelLink::configure()->resolveStyleUsing(
 *         fn (Model $record): ?PillStyle => $record instanceof Customer
 *             ? PillStyle::make(color: $record->primary_color, image: $record->favicon_url)
 *             : null,
 *     );
 *
 * An explicit `Pill::color()` / `Pill::icon()` / `Pill::image()` still beats it.
 */
final class PillStyle
{
    /**
     * @param  string|null  $color  A Filament palette name (`'danger'`) or a free color
     *                              (`'#0ea5e9'`, `'#0ae'`, `'rgb(14, 165, 233)'`).
     * @param  string|BackedEnum|null  $icon  Replaces the class-level icon.
     * @param  string|null  $image  URL of an image shown in the icon slot (a favicon). Wins
     *                              over `$icon`, which then serves as its fallback when the
     *                              image fails to load.
     */
    public function __construct(
        public readonly ?string $color = null,
        public readonly string|BackedEnum|null $icon = null,
        public readonly ?string $image = null,
    ) {}

    public static function make(
        ?string $color = null,
        string|BackedEnum|null $icon = null,
        ?string $image = null,
    ): self {
        return new self($color, $icon, $image);
    }
}
