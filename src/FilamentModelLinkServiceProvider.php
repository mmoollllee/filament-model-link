<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink;

use Illuminate\Support\ServiceProvider;

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
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filament-model-link.php' => config_path('filament-model-link.php'),
            ], 'filament-model-link-config');

            $this->publishes([
                __DIR__.'/../.ai/guidelines/filament-model-link.md' => base_path('.ai/guidelines/filament-model-link.md'),
            ], 'filament-model-link-ai-guidelines');
        }
    }
}
