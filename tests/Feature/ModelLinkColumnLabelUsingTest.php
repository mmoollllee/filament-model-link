<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Mmoollllee\FilamentModelLink\Tables\Columns\ModelLinkColumn;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\Article;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\ArticlesTable;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\Tag;

beforeEach(function (): void {
    $this->alpha = Tag::create(['name' => 'Alpha']);
    $this->beta = Tag::create(['name' => 'Beta']);

    $this->article = Article::create(['title' => 'First', 'featured_tag_id' => $this->alpha->id]);
    $this->article->tags()->attach($this->beta);
});

afterEach(function (): void {
    ArticlesTable::$columns = null;
});

function internal(): Closure
{
    return fn (Model $model): string => 'Intern '.$model->name;
}

it('labels a to-one pill and its search text the same way', function (): void {
    ArticlesTable::$columns = fn (): array => [ModelLinkColumn::make('featuredTag')->labelUsing(internal())];

    Livewire::test(ArticlesTable::class)
        ->assertSee('Intern Alpha')
        ->assertTableColumnStateSet('featuredTag', 'Intern Alpha', $this->article);
});

it('labels every pill of a to-many cell', function (): void {
    ArticlesTable::$columns = fn (): array => [ModelLinkColumn::make('tags')->labelUsing(internal())];

    Livewire::test(ArticlesTable::class)->assertSee('Intern Beta');
});

it('labels the merged relations of a multi-relation cell and their text', function (): void {
    ArticlesTable::$columns = fn (): array => [
        ModelLinkColumn::make('links')->relationships(['featuredTag', 'tags'])->labelUsing(internal()),
    ];

    Livewire::test(ArticlesTable::class)
        ->assertSee(['Intern Alpha', 'Intern Beta'])
        ->assertTableColumnStateSet('links', 'Intern Alpha, Intern Beta', $this->article);
});

it('changes nothing without it', function (): void {
    ArticlesTable::$columns = fn (): array => [ModelLinkColumn::make('featuredTag')];

    Livewire::test(ArticlesTable::class)
        ->assertSee('Alpha')
        ->assertDontSee('Intern Alpha');
});
