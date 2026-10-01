<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Tests\Feature;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Mmoollllee\FilamentModelLink\FilamentModelLinkServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * A real Filament table on a real Livewire component, against an in-memory
 * SQLite database — what an inline column needs to be tested through the same
 * endpoints the browser calls (`updateTableColumnState`, `callTableColumnMethod`).
 *
 * Kept apart from the unit suite on purpose: that one runs without Filament's
 * providers (no heroicons, no panel), and several of its tests pin exactly that.
 */
abstract class TableTestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Order matters: filament/support binds Livewire's DataStore to its own
        // subclass, which only sticks when it registers BEFORE Livewire — the
        // order package discovery produces in a real app (alphabetical).
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            LivewireServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            FilamentServiceProvider::class,
            FilamentModelLinkServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {

        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->json('tag_ids')->nullable();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('retired')->default(false);
        });

        Schema::create('article_tag', function (Blueprint $table): void {
            $table->foreignId('article_id');
            $table->foreignId('tag_id');
        });
    }
}
