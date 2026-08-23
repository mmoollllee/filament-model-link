<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Mmoollllee\FilamentModelLink\FilamentModelLink;
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;
use Mmoollllee\FilamentModelLink\Pill;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\PillModel;

beforeEach(function (): void {
    FilamentModelLink::configure()->registerCustomUrlResolver(fn () => '/resolved');
});

it('renders both label sources of a select from the same markup', function (): void {
    $model = PillModel::fake(['name' => 'Alpha'], id: 1);

    $select = Select::make('author_id')->pillOptions(collect([$model]));

    // The invariant the macro exists for: whichever source Filament reaches for
    // — the options array or the record callback — the user sees one pill.
    expect($select->getOptions()[1])->toBe($select->getOptionLabelFromRecord($model));
});

it('makes a selected value navigable and marks the option as HTML', function (): void {
    $model = PillModel::fake(['name' => 'Alpha'], id: 1);

    $select = Select::make('author_id')->pillOptions(collect([$model]));

    expect($select->isHtmlAllowed())->toBeTrue()
        ->and($select->isNative())->toBeFalse()
        ->and($select->getOptions()[1])
        ->toContain('href="/resolved"')
        ->toContain('fi-pill-clickthrough');
});

it('can opt out of click-through and linking', function (): void {
    $model = PillModel::fake(['name' => 'Alpha'], id: 1);

    $plain = Select::make('author_id')->pillOptions(collect([$model]), clickthrough: false);
    $unlinked = Select::make('author_id')->pillOptions(collect([$model]), linked: false);

    expect($plain->getOptions()[1])
        ->toContain('href="/resolved"')
        ->not->toContain('fi-pill-clickthrough')
        ->and($unlinked->getOptions()[1])
        ->not->toContain('<a ');
});

it('accepts a closure, a label attribute and a label callback', function (): void {
    $model = PillModel::fake(['name' => 'Alpha', 'email' => 'a@example.test'], id: 1);

    expect(Select::make('a')->pillOptions(fn () => collect([$model]))->getOptions()[1])->toContain('Alpha')
        ->and(Select::make('a')->pillOptions(collect([$model]), 'email')->getOptions()[1])->toContain('a@example.test')
        ->and(Select::make('a')->pillOptions(collect([$model]), labelCallback: fn (PillModel $m): string => strtoupper((string) $m->name))->getOptions()[1])
        ->toContain('ALPHA');
});

it('leaves the options alone on a relationship select but still labels its records', function (): void {
    $model = PillModel::fake(['name' => 'Alpha'], id: 1);

    // Without models Filament builds the options from the relationship itself,
    // rendering each record through the callback — so one pill still serves
    // both the dropdown and the chips.
    $select = Select::make('authors')->pillOptions();

    expect($select->getOptions())->toBe([])
        ->and($select->getOptionLabelFromRecord($model))->toContain('fi-pill-clickthrough');
});

it('adds the click-through flag to the presenter helpers', function (): void {
    $model = PillModel::fake(['name' => 'Alpha'], id: 1);

    expect(ModelReferencePresenter::modelSelectOptions(collect([$model]), linked: true)[1])
        ->not->toContain('fi-pill-clickthrough')
        ->and(ModelReferencePresenter::modelSelectOptions(collect([$model]), linked: true, clickthrough: true)[1])
        ->toContain('fi-pill-clickthrough')
        ->and(ModelReferencePresenter::modelSelectOption($model, linked: true, clickthrough: true))
        ->toBe(ModelReferencePresenter::modelSelectOptions(collect([$model]), linked: true, clickthrough: true)[1]);
});

it('takes a renderer for pill flavors the presenter cannot infer', function (): void {
    $model = PillModel::fake(['name' => 'Alpha'], id: 1);

    // A per-record icon, a project wrapper — whatever it is, it has to reach
    // both label sources, or the chip and the option disagree.
    $select = Select::make('author_id')->pillOptions(
        collect([$model]),
        renderUsing: fn (PillModel $record): string => Pill::for($record)
            ->icon('heroicon-o-star')
            ->label('Custom '.$record->name)
            ->linked()
            ->clickthrough()
            ->toHtml(),
    );

    expect($select->getOptions()[1])
        ->toContain('Custom Alpha')
        ->toContain('fi-pill-clickthrough')
        ->toBe($select->getOptionLabelFromRecord($model));
});

it('runs the models closure through the component evaluator', function (): void {
    $model = PillModel::fake(['name' => 'Alpha'], id: 1);

    // A real options list is rarely static — it reads a sibling field or keeps
    // the record's current value in a filtered list. Both need Filament's
    // injections, so the closure goes through the component's evaluator.
    $select = Select::make('author_id')->pillOptions(
        fn (Select $component): array => $component->getName() === 'author_id' ? [$model] : [],
    );

    expect($select->getOptions())->toHaveKey(1);
});
