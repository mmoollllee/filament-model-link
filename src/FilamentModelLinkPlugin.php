<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;

/**
 * Optional Filament plugin wrapper around the package configuration.
 *
 * Use this when you prefer the panel-scoped `Filament::registerPlugin(...)`
 * pattern over wiring everything in `AppServiceProvider::boot()`. Note that
 * the resolvers themselves are still global (static on `ModelReferencePresenter`)
 * because pill rendering happens outside any panel context (e.g. inside a
 * resource's `globalSearchResultDetails()`, queue notifications, …).
 *
 *     // In a Filament PanelProvider:
 *     $panel->plugin(
 *         FilamentModelLinkPlugin::make()
 *             ->resolveIconUsing(fn (string $class) => …)
 *             ->registerCustomUrlResolver(fn (...) => …),
 *     );
 */
class FilamentModelLinkPlugin implements Plugin
{
    /** @var array<int, Closure> */
    protected array $deferredCalls = [];

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filament-model-link';
    }

    public function register(Panel $panel): void
    {
        foreach ($this->deferredCalls as $call) {
            $call();
        }

        $this->deferredCalls = [];
    }

    public function boot(Panel $panel): void
    {
        // no-op
    }

    /**
     * @param  Closure(string $modelClass): (string|\BackedEnum|null)  $resolver
     */
    public function resolveIconUsing(Closure $resolver): static
    {
        $this->deferredCalls[] = fn () => ModelReferencePresenter::resolveIconUsing($resolver);

        return $this;
    }

    /**
     * @param  Closure(Model $related): array{0: mixed, 1: ?string}  $resolver
     */
    public function resolveResourceUsing(Closure $resolver): static
    {
        $this->deferredCalls[] = fn () => ModelReferencePresenter::resolveResourceUsing($resolver);

        return $this;
    }

    /**
     * @param  Closure(Model $related, ?string $panel): array<string, mixed>  $resolver
     */
    public function resolveResourceParametersUsing(Closure $resolver): static
    {
        $this->deferredCalls[] = fn () => ModelReferencePresenter::resolveResourceParametersUsing($resolver);

        return $this;
    }

    /**
     * @param  Closure(mixed $related, iterable<int, string> $viewTypes, mixed $sourceRecord): ?string  $resolver
     */
    public function registerCustomUrlResolver(Closure $resolver): static
    {
        $this->deferredCalls[] = fn () => ModelReferencePresenter::registerCustomUrlResolver($resolver);

        return $this;
    }
}
