<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Fluent entry point for wiring the package. Use inside `AppServiceProvider::boot()`
 * — it groups every resolver setting under a single chain instead of three
 * separate `ModelReferencePresenter::resolve*Using()` calls.
 *
 *     FilamentModelLink::configure()
 *         ->resolveIconUsing(fn (string $class) => …)
 *         ->resolveResourceParametersUsing(fn ($related, $panel) => …)
 *         ->registerCustomUrlResolver(fn ($related, $viewTypes, $source) => …);
 */
final class FilamentModelLink
{
    public static function configure(): self
    {
        return new self;
    }

    /**
     * @param  Closure(string $modelClass): (string|\BackedEnum|null)  $resolver
     */
    public function resolveIconUsing(Closure $resolver): self
    {
        ModelReferencePresenter::resolveIconUsing($resolver);

        return $this;
    }

    /**
     * @param  Closure(Model $related): array{0: mixed, 1: ?string}  $resolver
     */
    public function resolveResourceUsing(Closure $resolver): self
    {
        ModelReferencePresenter::resolveResourceUsing($resolver);

        return $this;
    }

    /**
     * @param  Closure(Model $related, ?string $panel): array<string, mixed>  $resolver
     */
    public function resolveResourceParametersUsing(Closure $resolver): self
    {
        ModelReferencePresenter::resolveResourceParametersUsing($resolver);

        return $this;
    }

    /**
     * @param  Closure(mixed $related, iterable<int, string> $viewTypes, mixed $sourceRecord): ?string  $resolver
     */
    public function registerCustomUrlResolver(Closure $resolver): self
    {
        ModelReferencePresenter::registerCustomUrlResolver($resolver);

        return $this;
    }

    /**
     * Wipe every configured hook (intended for tests).
     */
    public static function flush(): void
    {
        ModelReferencePresenter::flush();
    }
}
