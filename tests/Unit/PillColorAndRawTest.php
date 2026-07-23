<?php

declare(strict_types=1);

use Mmoollllee\FilamentModelLink\ModelReferencePresenter;
use Mmoollllee\FilamentModelLink\Pill;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\PillModel;

it('renders a raw pill without a model', function (): void {
    $html = ModelReferencePresenter::renderPill('01.08.2026', 'danger', url: '/notes/10');

    expect($html)
        ->toContain('fi-color-danger')
        ->toContain('01.08.2026')
        ->toContain('href="/notes/10"');
});

it('falls back to the default color on a raw pill', function (): void {
    config()->set('filament-model-link.default_color', 'gray');

    expect(ModelReferencePresenter::renderPill('leer'))->toContain('fi-color-gray');
});

it('escapes the raw pill label and icon tooltip', function (): void {
    $html = ModelReferencePresenter::renderPill('<b>x</b>');

    expect($html)
        ->toContain('&lt;b&gt;x&lt;/b&gt;')
        ->not->toContain('<b>x</b>');
});

it('builds a raw pill via Pill::make with color, icon slot, and url', function (): void {
    $html = Pill::make('01.08.2026')->color('success')->url('/notes/10')->toHtml();

    expect($html)
        ->toContain('fi-color-success')
        ->toContain('01.08.2026')
        ->toContain('href="/notes/10"');
});

it('ignores linked() on a model-less pill instead of failing', function (): void {
    expect(Pill::make('nur-text')->linked()->toHtml())
        ->toContain('nur-text')
        ->not->toContain('<a ');
});

it('overrides the HasPills color on a standalone pill', function (): void {
    $model = PillModel::fake(['name' => 'Solo'], id: 1);

    expect(Pill::for($model)->color('danger')->toHtml())
        ->toContain('fi-color-danger')
        ->not->toContain('fi-color-gray');
});

it('recolors only the target segment of a chain', function (): void {
    $parent = PillModel::fake(['name' => 'Parent'], id: 1);
    $child = PillModel::fake(['name' => 'Child'], id: 2);
    $child->parent = $parent;

    $html = Pill::chain($child)->color('danger')->toHtml();

    expect($html)
        ->toContain('fi-color-danger')  // Ziel
        ->toContain('fi-color-gray');   // Ancestor unverändert
});

it('applies the color override to icon-override pills', function (): void {
    $model = PillModel::fake(['name' => 'Solo'], id: 1);

    $html = Pill::for($model)->iconTooltip('Rolle')->color('warning')->toHtml();

    expect($html)->toContain('fi-color-warning');
});

it('marks only linked pills with fi-pill-link so CSS can target them', function (): void {
    $model = PillModel::fake(['name' => 'Solo'], id: 1);

    expect(Pill::for($model)->url('/x')->toHtml())->toContain('fi-pill-link')
        ->and(Pill::for($model)->toHtml())->not->toContain('fi-pill-link')
        ->and(ModelReferencePresenter::renderPill('Wert'))->not->toContain('fi-pill-link')
        ->and(ModelReferencePresenter::renderPill('Wert', url: '/x'))->toContain('fi-pill-link');
});

it('no longer bakes a hover style into the markup — the stylesheet owns it', function (): void {
    $model = PillModel::fake(['name' => 'Solo'], id: 1);

    expect(Pill::for($model)->url('/x')->toHtml())->not->toContain('hover:underline');
});
