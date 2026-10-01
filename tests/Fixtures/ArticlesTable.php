<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Tests\Fixtures;

use Closure;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

/**
 * A Livewire table whose columns a test hands in — the smallest real host for
 * an inline column.
 */
class ArticlesTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** @var (Closure(): array<int, Column>)|null */
    public static ?Closure $columns = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(Article::query())
            ->columns([
                TextColumn::make('title'),
                ...(self::$columns !== null ? (self::$columns)() : []),
            ]);
    }

    public function render(): string
    {
        return '<div>{{ $this->table }}</div>';
    }
}
