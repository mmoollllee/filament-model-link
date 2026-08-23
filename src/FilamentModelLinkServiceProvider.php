<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink;

use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Support\ServiceProvider;
use Mmoollllee\FilamentModelLink\Forms\PillSelect;

class FilamentModelLinkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/filament-model-link.php',
            'filament-model-link',
        );
    }

    public function boot(): void
    {
        $this->registerSelectMacro();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filament-model-link.php' => config_path('filament-model-link.php'),
            ], 'filament-model-link-config');

            $this->publishes([
                __DIR__.'/../.ai/guidelines/filament-model-link.md' => base_path('.ai/guidelines/filament-model-link.md'),
            ], 'filament-model-link-ai-guidelines');

            // Optional: only needed by projects that prefer a local copy over
            // importing the file straight out of vendor/ in their panel theme.
            $this->publishes([
                __DIR__.'/../resources/css/filament-model-link.css' => resource_path('css/vendor/filament-model-link.css'),
            ], 'filament-model-link-styles');
        }
    }

    /**
     * `Select::pillOptions()` — pill options, pill chips and the settings both
     * need, in one call. See {@see PillSelect} for what it wires and why.
     *
     * The macro closure runs bound to the `Select` it is called on, so it hands
     * that instance to {@see PillSelect::apply()} and returns it for chaining.
     */
    protected function registerSelectMacro(): void
    {
        if (Select::hasMacro('pillOptions')) {
            return;
        }

        Select::macro('pillOptions', function (
            iterable|Closure|null $models = null,
            ?string $labelAttribute = null,
            ?Closure $labelCallback = null,
            bool $linked = true,
            bool $clickthrough = true,
            ?Closure $renderUsing = null,
        ): Select {
            return PillSelect::apply(
                PillSelect::select($this),
                $models,
                $labelAttribute,
                $labelCallback,
                $linked,
                $clickthrough,
                $renderUsing,
            );
        });
    }
}
