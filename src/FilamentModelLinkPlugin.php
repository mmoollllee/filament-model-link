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
 * Use this when you prefer the `$panel->plugin(...)` pattern over wiring
 * everything in `AppServiceProvider::boot()`.
 *
 * **The resolvers are process-global** (static on `ModelReferencePresenter`)
 * because pill rendering also happens outside any panel context (queue
 * notifications, console, global search). Applying them once is therefore
 * enough — and `register()` is idempotent, so adding the **same configured
 * instance** to several panels is safe.
 *
 * For a multi-panel app, configure once and share the instance across panels —
 * e.g. via a small memoized factory, so the config is built before Filament
 * calls `register()`:
 *
 *     final class ModelLinkPlugin
 *     {
 *         private static ?FilamentModelLinkPlugin $plugin = null;
 *
 *         public static function make(): FilamentModelLinkPlugin
 *         {
 *             return self::$plugin ??= FilamentModelLinkPlugin::make()
 *                 ->resolveIconUsing(fn (string $class) => …)
 *                 ->registerCustomUrlResolver(fn (...) => …);
 *         }
 *     }
 *
 *     // In every PanelProvider:
 *     $panel->plugin(ModelLinkPlugin::make());
 *
 * Do NOT re-chain the resolvers in each panel — `registerCustomUrlResolver()`
 * is additive, so configuring twice would register the same resolver twice.
 */
class FilamentModelLinkPlugin implements Plugin
{
    /** @var array<int, Closure> */
    protected array $deferredCalls = [];

    protected bool $registered = false;

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
        // Resolvers are global — apply them once, even across multiple panels.
        if ($this->registered) {
            return;
        }

        foreach ($this->deferredCalls as $call) {
            $call();
        }

        $this->deferredCalls = [];
        $this->registered = true;
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
