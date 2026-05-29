<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Contracts;

/**
 * Per-instance label shown in pills, chain links, and tooltips. When a model
 * implements this, ModelReferencePresenter::basePillLabel() delegates here
 * instead of falling back to a generic `name`/`title` read.
 */
interface HasPillLabel
{
    public function pillLabel(): string;
}
