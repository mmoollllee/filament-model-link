<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Support\ServiceProvider;
use Mmoollllee\FilamentModelLink\Forms\PillSelect;
use Mmoollllee\FilamentModelLink\Tables\Columns\MultiSelectColumn;

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
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filament-model-link');

        $this->registerSelectMacro();
        $this->registerSelectColumnMacro();
        $this->registerBorderlessMacro();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filament-model-link.php' => config_path('filament-model-link.php'),
            ], 'filament-model-link-config');

            $this->publishes([
                __DIR__.'/../.ai/guidelines/filament-model-link.md' => base_path('.ai/guidelines/filament-model-link.md'),
            ], 'filament-model-link-ai-guidelines');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/filament-model-link'),
            ], 'filament-model-link-translations');

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
            ?bool $searchable = null,
            ?Closure $searchUsing = null,
        ): Select {
            return PillSelect::apply(
                PillSelect::select($this),
                $models,
                $labelAttribute,
                $labelCallback,
                $linked,
                $clickthrough,
                $renderUsing,
                $searchable,
                $searchUsing,
            );
        });
    }

    /**
     * `SelectColumn::pillOptions()` — the same recipe for an editable table
     * cell that holds one assignment. See {@see PillSelect::applyToColumn()}.
     */
    protected function registerSelectColumnMacro(): void
    {
        if (SelectColumn::hasMacro('pillOptions')) {
            return;
        }

        SelectColumn::macro('pillOptions', function (
            iterable|Closure|null $models = null,
            ?string $labelAttribute = null,
            ?Closure $labelCallback = null,
            bool $linked = true,
            bool $clickthrough = true,
            ?Closure $renderUsing = null,
            ?bool $searchable = null,
            ?Closure $searchUsing = null,
        ): SelectColumn {
            return PillSelect::applyToColumn(
                PillSelect::column($this),
                $models,
                $labelAttribute,
                $labelCallback,
                $linked,
                $clickthrough,
                $renderUsing,
                $searchable,
                $searchUsing,
            );
        });
    }

    /**
     * `SelectColumn::borderless()` — the inline-edit look of a
     * {@see MultiSelectColumn} for Filament's own single-value column: the input
     * frame shows on hover or focus only. Opt-in here, because a plain
     * `SelectColumn` is Filament's and keeps Filament's look unless asked;
     * `MultiSelectColumn` has the look by default and a real `borderless()`
     * method that takes precedence over this macro.
     */
    protected function registerBorderlessMacro(): void
    {
        if (SelectColumn::hasMacro('borderless')) {
            return;
        }

        SelectColumn::macro('borderless', function (bool|Closure $condition = true): SelectColumn {
            $column = PillSelect::column($this);

            return $column->extraAttributes(
                static fn (SelectColumn $column): array => $column->evaluate($condition)
                    ? ['class' => MultiSelectColumn::BORDERLESS_CLASS]
                    : [],
                merge: true,
            );
        });
    }
}
