<?php

declare(strict_types=1);

use Mmoollllee\FilamentModelLink\FilamentModelLink;
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;
use Mmoollllee\FilamentModelLink\Tables\Columns\ModelLinkColumn;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\PillModel;

it('leaves the click alone by default', function (): void {
    $model = PillModel::fake(['name' => 'Alpha'], id: 1);

    // Default matters most in select dropdowns: Filament has to receive the
    // click there, otherwise the option is never picked.
    expect(ModelReferencePresenter::renderStandalonePill($model, 'Alpha', '/x'))
        ->not->toContain('x-on:click.stop')
        ->not->toContain('fi-pill-clickthrough');
});

it('stops the click on linked pills when asked to', function (): void {
    $model = PillModel::fake(['name' => 'Alpha'], id: 1);

    // The class is the stylesheet's half of the deal: it keeps the pill's
    // pointer events inside a select's value container.
    expect(ModelReferencePresenter::renderStandalonePill($model, 'Alpha', '/x', stopClickPropagation: true))
        ->toContain('x-on:click.stop')
        ->toContain('fi-pill-link')
        ->toContain('fi-pill-clickthrough');
});

it('does not stop the click on pills without a link', function (): void {
    $model = PillModel::fake(['name' => 'Alpha'], id: 1);

    // Nothing to protect: an unlinked pill is a `<span>`, so the cell action
    // should still fire when it is clicked.
    expect(ModelReferencePresenter::renderStandalonePill($model, 'Alpha', null, stopClickPropagation: true))
        ->not->toContain('x-on:click.stop');
});

it('passes the flag through chains and chain containers', function (): void {
    FilamentModelLink::configure()->registerCustomUrlResolver(fn () => '/resolved');

    $parent = PillModel::fake(['name' => 'Parent'], id: 1);
    $child = PillModel::fake(['name' => 'Child'], id: 2);
    $child->parent = $parent;

    $chain = ModelReferencePresenter::renderPillChain($child, '/child', stopClickPropagation: true);
    $chains = ModelReferencePresenter::renderPillChains([$child], stopClickPropagation: true);

    // Every linked segment needs it — an unprotected ancestor pill would open
    // the cell action instead of navigating.
    expect(substr_count($chain, 'x-on:click.stop'))->toBe(2)
        ->and(substr_count($chains, 'x-on:click.stop'))->toBe(2)
        ->and(ModelReferencePresenter::renderPillChains([$child]))
        ->not->toContain('x-on:click.stop');
});

it('re-enables cell clicks on a column that gets an action, and restores the guard without one', function (): void {
    $withAction = ModelLinkColumn::make('links')->action(fn () => null);
    $plain = ModelLinkColumn::make('links');

    expect($withAction->isClickDisabled())->toBeFalse()
        ->and($plain->isClickDisabled())->toBeTrue()
        ->and($withAction->action(null)->isClickDisabled())->toBeTrue();
});

it('keeps the cell click enabled when the column is re-initialized after the action', function (): void {
    // `overwriteName()` re-runs initialize(); it must not undo action().
    $column = ModelLinkColumn::make('links')
        ->action(fn () => null)
        ->overwriteName('owner');

    expect($column->isClickDisabled())->toBeFalse();
});
