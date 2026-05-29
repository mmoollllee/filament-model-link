<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in instance-level contract: record-specific parent whose pill should be
 * rendered *before* this model's pill in a chain (e.g. Post → Team).
 *
 * Unlike HasPills (static class-level label/color), the parent is tied to a
 * specific record, so this must be an instance method.
 */
interface HasPillParent
{
    public function pillParent(): ?Model;
}
