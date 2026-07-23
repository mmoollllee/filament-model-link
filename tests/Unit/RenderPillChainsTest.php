<?php

declare(strict_types=1);

use Mmoollllee\FilamentModelLink\ModelReferencePresenter;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\PillModel;

it('renders one chain per model inside the wrapper', function (): void {
    $a = PillModel::fake(['name' => 'Alpha'], id: 1);
    $b = PillModel::fake(['name' => 'Beta'], id: 2);

    $html = ModelReferencePresenter::renderPillChains([$a, $b]);

    expect($html)
        ->toContain('fi-pill-chains')
        ->toContain('Alpha')
        ->toContain('Beta');
});

it('returns an empty string when nothing is renderable', function (): void {
    expect(ModelReferencePresenter::renderPillChains([]))->toBe('')
        ->and(ModelReferencePresenter::renderPillChains([null, 'not-a-model', 42]))->toBe('');
});

it('accepts collections as well as arrays', function (): void {
    $a = PillModel::fake(['name' => 'Alpha'], id: 1);

    expect(ModelReferencePresenter::renderPillChains(collect([$a])))
        ->toContain('Alpha');
});

it('renders exact duplicates only once', function (): void {
    $a = PillModel::fake(['name' => 'Alpha'], id: 1);
    $again = PillModel::fake(['name' => 'Alpha'], id: 1);

    $html = ModelReferencePresenter::renderPillChains([$a, $again, $a]);

    expect(substr_count($html, 'Alpha'))->toBe(1);
});

it('skips models that already appear as chain ancestors of another entry', function (): void {
    $parent = PillModel::fake(['name' => 'Parent'], id: 1);
    $child = PillModel::fake(['name' => 'Child'], id: 2);
    $child->parent = $parent;

    $html = ModelReferencePresenter::renderPillChains([$parent, $child]);

    // "Parent" renders once — as the child's chain segment, not standalone.
    // The closing quote separates `fi-pill-chain"` from the `fi-pill-chains"`
    // wrapper; both are bare hook classes now, so a prefix match would count both.
    expect(substr_count($html, 'Parent'))->toBe(1)
        ->and(substr_count($html, 'fi-pill-chain"'))->toBe(1);
});

it('falls back to rendering every entry when a parent cycle marks all as ancestors', function (): void {
    $a = PillModel::fake(['name' => 'CycleA'], id: 1);
    $b = PillModel::fake(['name' => 'CycleB'], id: 2);
    $a->parent = $b;
    $b->parent = $a;

    $html = ModelReferencePresenter::renderPillChains([$a, $b]);

    expect($html)
        ->toContain('CycleA')
        ->toContain('CycleB');
});

it('collapses the overflow into a +N pill listing the hidden labels', function (): void {
    $a = PillModel::fake(['name' => 'Alpha'], id: 1);
    $b = PillModel::fake(['name' => 'Beta'], id: 2);
    $c = PillModel::fake(['name' => 'Gamma'], id: 3);

    $html = ModelReferencePresenter::renderPillChains([$a, $b, $c], maxPills: 2);

    expect($html)
        ->toContain('Alpha')
        ->toContain('Beta')
        ->toContain('>+1<')
        ->toContain('title="Gamma"')
        ->not->toContain('fi-badge fi-size-sm fi-color fi-color-gray">Gamma');
});

it('renders no overflow pill when maxPills covers every entry', function (): void {
    $a = PillModel::fake(['name' => 'Alpha'], id: 1);
    $b = PillModel::fake(['name' => 'Beta'], id: 2);

    $html = ModelReferencePresenter::renderPillChains([$a, $b], maxPills: 2);

    expect($html)->not->toContain('>+');
});

it('treats a non-positive maxPills as unlimited', function (): void {
    $a = PillModel::fake(['name' => 'Alpha'], id: 1);
    $b = PillModel::fake(['name' => 'Beta'], id: 2);

    $html = ModelReferencePresenter::renderPillChains([$a, $b], maxPills: 0);

    expect($html)
        ->toContain('Alpha')
        ->toContain('Beta')
        ->not->toContain('>+');
});

it('applies the label limit to every rendered chain', function (): void {
    $a = PillModel::fake(['name' => 'Extraordinarily long name'], id: 1);

    $html = ModelReferencePresenter::renderPillChains([$a], labelLimit: 10);

    expect($html)
        ->toContain('Extraordin')
        ->not->toContain('Extraordinarily long name');
});
