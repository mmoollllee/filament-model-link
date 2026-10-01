<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\FilamentModelLink\Forms\Components\ModelLink;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\Article;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\ArticlesTable;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\Tag;

it('labels the pills of the form component as well', function (): void {
    $article = Article::create(['title' => 'First', 'featured_tag_id' => Tag::create(['name' => 'Alpha'])->id]);

    $content = fn (ModelLink $component): string => (string) $component
        ->container(Schema::make(app(ArticlesTable::class))->record($article))
        ->getContent();

    expect($content(ModelLink::make('featuredTag')->labelUsing(fn (Model $model): string => 'Intern '.$model->name)))
        ->toContain('Intern Alpha')
        ->and($content(ModelLink::make('featuredTag')))
        ->toContain('Alpha')
        ->not->toContain('Intern');
});
