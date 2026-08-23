<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Mmoollllee\FilamentModelLink\FilamentModelLink;
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;
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
