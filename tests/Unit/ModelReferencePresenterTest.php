<?php

declare(strict_types=1);

use Mmoollllee\FilamentModelLink\FilamentModelLink;
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\PillModel;

it('renders a standalone pill with the configured color and label', function (): void {
    $model = PillModel::fake(['name' => 'Hello']);

    $html = ModelReferencePresenter::renderStandalonePill($model, 'Hello');

    expect($html)
        ->toContain('fi-badge')
        ->toContain('fi-color-gray')
        ->toContain('Hello');
});

it('reads label and color from HasPills', function (): void {
    $class = PillModel::withClassLabel('Custom', 'success');
    $model = (new $class)->forceFill(['id' => 1, 'name' => 'X']);
    $model->exists = true;

    $html = ModelReferencePresenter::renderStandalonePill($model, 'X');

    expect($html)->toContain('fi-color-success');
});

it('uses HasPillLabel for the basePillLabel default', function (): void {
    $model = PillModel::fake(['name' => 'OverrideMe']);

    expect(ModelReferencePresenter::basePillLabel($model))->toBe('OverrideMe');
});

it('walks HasPillParent into a chain', function (): void {
    $parent = PillModel::fake(['name' => 'Parent'], id: 1);
    $child = PillModel::fake(['name' => 'Child'], id: 2);
    $child->parent = $parent;

    $chain = ModelReferencePresenter::pillChainModels($child);

    expect($chain)->toHaveCount(2)
        ->and($chain[0]->name)->toBe('Parent')
        ->and($chain[1]->name)->toBe('Child');
});

it('renders a chained pill with the chain wrapper class', function (): void {
    $parent = PillModel::fake(['name' => 'Parent'], id: 1);
    $child = PillModel::fake(['name' => 'Child'], id: 2);
    $child->parent = $parent;

    $html = ModelReferencePresenter::renderPillChain($child);

    expect($html)
        ->toContain('fi-pill-chain')
        ->toContain('Parent')
        ->toContain('Child');
});

it('collapses a single-element chain to a plain pill', function (): void {
    $model = PillModel::fake(['name' => 'Solo'], id: 1);

    $html = ModelReferencePresenter::renderPillChain($model);

    expect($html)
        ->not->toContain('fi-pill-chain')
        ->toContain('Solo');
});

it('caps the chain at the configured depth', function (): void {
    config()->set('filament-model-link.pill_chain_max_depth', 3);

    $a = PillModel::fake(['name' => 'A'], id: 1);
    $b = PillModel::fake(['name' => 'B'], id: 2);
    $c = PillModel::fake(['name' => 'C'], id: 3);
    $d = PillModel::fake(['name' => 'D'], id: 4);
    $e = PillModel::fake(['name' => 'E'], id: 5);

    $b->parent = $a;
    $c->parent = $b;
    $d->parent = $c;
    $e->parent = $d;

    expect(ModelReferencePresenter::pillChainModels($e))->toHaveCount(3);
});

it('builds a plain text chain joined with the separator', function (): void {
    $parent = PillModel::fake(['name' => 'Parent'], id: 1);
    $child = PillModel::fake(['name' => 'Child'], id: 2);
    $child->parent = $parent;

    expect(ModelReferencePresenter::textChain($child))->toBe('Parent › Child');
    expect(ModelReferencePresenter::textChain($child, ' / '))->toBe('Parent / Child');
});

it('invokes the configured icon resolver with the model class', function (): void {
    $captured = null;

    FilamentModelLink::configure()
        ->resolveIconUsing(function (string $class) use (&$captured) {
            $captured = $class;

            return null;
        });

    ModelReferencePresenter::renderStandalonePill(PillModel::fake(), 'X');

    expect($captured)->toBe(PillModel::class);
});

it('skips icon rendering entirely when no resolver is configured', function (): void {
    $model = PillModel::fake(['name' => 'NoIcon']);

    $html = ModelReferencePresenter::renderStandalonePill($model, 'NoIcon');

    // No resolver → no icon HTML appended before the label.
    expect($html)->toBe('<span class="fi-badge fi-size-sm fi-color fi-color-gray">NoIcon</span>');
});

it('returns null URL when no resource, no resolver, no custom URL builder', function (): void {
    $model = PillModel::fake(['name' => 'NoUrl']);

    expect(ModelReferencePresenter::urlForRelated($model))->toBeNull();
});

it('routes through a custom URL resolver before falling back to resources', function (): void {
    FilamentModelLink::configure()
        ->registerCustomUrlResolver(
            fn ($related) => $related instanceof PillModel ? 'https://example.test/custom' : null,
        );

    expect(ModelReferencePresenter::urlForRelated(PillModel::fake()))
        ->toBe('https://example.test/custom');
});

it('falls through when a custom URL resolver returns null', function (): void {
    FilamentModelLink::configure()
        ->registerCustomUrlResolver(fn () => null);

    expect(ModelReferencePresenter::urlForRelated(PillModel::fake()))->toBeNull();
});

it('defaults view types from config', function (): void {
    config()->set('filament-model-link.default_view_types', ['view']);

    expect(ModelReferencePresenter::defaultViewTypes())->toBe(['view']);
});

it('falls back to view+edit when the config view types are empty or invalid', function (): void {
    config()->set('filament-model-link.default_view_types', []);
    expect(ModelReferencePresenter::defaultViewTypes())->toBe(['view', 'edit']);

    config()->set('filament-model-link.default_view_types', 'nonsense');
    expect(ModelReferencePresenter::defaultViewTypes())->toBe(['view', 'edit']);
});

it('passes the resolved config view types (never null) to custom URL resolvers', function (): void {
    config()->set('filament-model-link.default_view_types', ['view']);

    $received = null;
    FilamentModelLink::configure()
        ->registerCustomUrlResolver(function ($related, $viewTypes) use (&$received) {
            $received = $viewTypes;

            return null;
        });

    // No explicit viewTypes passed → resolver must still get the config array.
    ModelReferencePresenter::urlForRelated(PillModel::fake());

    expect($received)->toBe(['view']);
});

it('resolves related record via dot path', function (): void {
    $related = PillModel::fake(['name' => 'Inner'], id: 2);
    $host = (object) ['post' => $related];

    expect(ModelReferencePresenter::resolveRelatedRecord($host, 'post.title'))->toBe($related);
});

it('uses the attribute side of dot path as the visible label fallback', function (): void {
    $related = PillModel::fake(['name' => 'Inner', 'title' => 'Spelling'], id: 2);
    $host = (object) ['post' => $related];

    expect(ModelReferencePresenter::displayLabel($host, 'post.title'))->toBe('Spelling');
});

it('falls back to basePillLabel when the dot attribute is empty', function (): void {
    $related = PillModel::fake(['name' => 'Inner'], id: 2);
    $host = (object) ['post' => $related];

    expect(ModelReferencePresenter::displayLabel($host, 'post.title'))->toBe('Inner');
});

it('returns null label / type when the related record is missing', function (): void {
    $host = (object) ['post' => null];

    expect(ModelReferencePresenter::displayLabel($host, 'post.title'))->toBeNull();
    expect(ModelReferencePresenter::typeBasename($host, 'post.title'))->toBeNull();
});
