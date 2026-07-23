<?php

declare(strict_types=1);

use Filament\Support\Icons\Heroicon;
use Mmoollllee\FilamentModelLink\FilamentModelLink;
use Mmoollllee\FilamentModelLink\Pill;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\PillModel;

it('builds a single pill', function (): void {
    $model = PillModel::fake(['name' => 'Solo']);

    $html = Pill::for($model)->toHtml();

    expect($html)
        ->toContain('fi-badge')
        ->toContain('Solo')
        ->not->toContain('fi-pill-chain');
});

it('builds a chain via chain() static', function (): void {
    $parent = PillModel::fake(['name' => 'Parent'], id: 1);
    $child = PillModel::fake(['name' => 'Child'], id: 2);
    $child->parent = $parent;

    $html = Pill::chain($child)->toHtml();

    expect($html)
        ->toContain('fi-pill-chain')
        ->toContain('Parent')
        ->toContain('Child');
});

it('overrides the label', function (): void {
    $model = PillModel::fake(['name' => 'Default']);

    $html = Pill::for($model)->label('Custom')->toHtml();

    expect($html)
        ->toContain('Custom')
        ->not->toContain('Default');
});

it('emits no icon span when the icon does not render', function (): void {
    $model = PillModel::fake(['name' => 'X']);

    $html = Pill::for($model)
        ->icon(Heroicon::Star)
        ->iconTooltip('Featured')
        ->toHtml();

    // The tooltip hangs on the icon span, so it exists only when an icon
    // actually rendered. Icon rendering needs blade-ui-kit/blade-heroicons,
    // which the package's own test environment does not install — so here the
    // span is correctly absent. Emitting it anyway (the old behaviour) put a
    // tooltip on a zero-width box nobody can hover, and cost a column gap.
    expect($html)
        ->not->toContain('title="Featured"')
        ->not->toContain('fi-pill-icon');
});

it('renders a link when an explicit URL is given', function (): void {
    $model = PillModel::fake(['name' => 'L']);

    $html = Pill::for($model)->url('https://example.test/x')->toHtml();

    expect($html)
        ->toContain('<a href="https://example.test/x"')
        ->toContain('fi-pill-link');
});

it('hangs no tooltip when there is no icon to hang it on', function (): void {
    $model = PillModel::fake(['name' => 'X']);

    // icon() omitted → the model's default icon carries the tooltip. With no
    // icon resolver registered and no heroicons package there is no icon, so
    // there is nothing to hang it on — and no empty span either.
    $html = Pill::for($model)->iconTooltip('Admin')->toHtml();

    expect($html)
        ->not->toContain('fi-pill-icon')
        ->toContain('fi-badge');
});

it('keeps an explicit URL when combined with an icon override', function (): void {
    $model = PillModel::fake(['name' => 'L']);

    // Regression: the icon-override path used to recompute the URL and drop ->url().
    $html = Pill::for($model)
        ->icon(Heroicon::Star)
        ->iconTooltip('Featured')
        ->url('https://example.test/custom')
        ->toHtml();

    expect($html)->toContain('<a href="https://example.test/custom"');
});

it('falls back to the configured custom URL resolver when linked', function (): void {
    FilamentModelLink::configure()
        ->registerCustomUrlResolver(fn ($r) => 'https://resolver.test/x');

    $model = PillModel::fake(['name' => 'L']);

    expect(Pill::for($model)->linked()->toHtml())
        ->toContain('<a href="https://resolver.test/x"');
});

it('stringifies via __toString and Htmlable', function (): void {
    $model = PillModel::fake(['name' => 'S']);
    $pill = Pill::for($model);

    expect((string) $pill)->toBe($pill->toHtml());
    expect($pill->toHtmlString()->toHtml())->toBe($pill->toHtml());
});
