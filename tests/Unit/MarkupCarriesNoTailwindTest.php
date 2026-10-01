<?php

declare(strict_types=1);

use Mmoollllee\FilamentModelLink\ModelReferencePresenter;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\PillModel;

/**
 * The package's PHP lives in the consumer's vendor/, which their Tailwind build
 * does not scan. A utility emitted from here only renders if their own app code
 * happens to use the same class — `gap-1.5`, `items-stretch` and `rounded-none`
 * did not, so chains shipped gapless and fully rounded for months.
 *
 * Markup therefore carries hook classes only (`fi-*`, which Filament's own theme
 * defines); everything visual belongs in resources/css/filament-model-link.css.
 */
function pillMarkupSamples(): array
{
    $parent = PillModel::fake(['name' => 'Parent'], id: 1);
    $child = PillModel::fake(['name' => 'Child'], id: 2);
    $child->parent = $parent;

    return [
        'standalone' => ModelReferencePresenter::renderStandalonePill($parent, 'Parent'),
        'chain' => ModelReferencePresenter::renderPillChain($child),
        'chains' => ModelReferencePresenter::renderPillChains([$child], maxPills: 1),
        'raw' => ModelReferencePresenter::renderPill('Roh', 'danger', url: '/x'),
        // The icon paths add their own wrapper span — cover them explicitly.
        'raw with icon' => ModelReferencePresenter::renderPill('Roh', icon: 'heroicon-o-user', iconTooltip: 'Rolle'),
        'clickthrough' => ModelReferencePresenter::renderStandalonePill($parent, 'Parent', '/x', stopClickPropagation: true),
        'icon override' => ModelReferencePresenter::renderPillWithIconOverride($parent, 'heroicon-o-user', 'Parent', 'Rolle'),
        // An image with a rendered type icon behind it — both new hooks at once.
        'image with fallback' => ModelReferencePresenter::renderPillWithIconOverride($parent, '/icons/type.svg', 'Parent', image: 'https://example.test/favicon.ico'),
        'image' => ModelReferencePresenter::renderPill('Acme', image: 'https://example.test/favicon.ico'),
        // A free color adds an inline style and a marker class, nothing else.
        'free color' => ModelReferencePresenter::renderPill('Acme', '#0ea5e9', url: '/x'),
    ];
}

it('emits only fi- prefixed hook classes', function (): void {
    foreach (pillMarkupSamples() as $context => $html) {
        preg_match_all('/class="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $attribute) {
            foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $class) {
                // `dark:fi-…` comes from Filament's own color resolver, not from
                // this package's markup — its variant prefix is Filament's to own.
                expect(str_starts_with($class, 'dark:') ? substr($class, 5) : $class)
                    ->toStartWith('fi-', "{$context} markup carries a non-hook class");
            }
        }
    }
});

it('keeps every class the stylesheet targets present in the markup', function (): void {
    $css = file_get_contents(__DIR__.'/../../resources/css/filament-model-link.css');
    $markup = implode(' ', pillMarkupSamples());

    // Hooks the stylesheet owns and this package emits itself. Filament's own
    // classes (fi-badge, fi-dropdown-list-item, …) are deliberately not listed.
    foreach (['fi-pill-chain', 'fi-pill-chains', 'fi-pill-link', 'fi-pill-clickthrough', 'fi-pill-image', 'fi-pill-image-slot', 'fi-pill-image-fallback'] as $hook) {
        expect($css)->toContain(".{$hook}")
            ->and($markup)->toContain($hook);
    }
});

/**
 * A pill is inline-flex, so inside one of Filament's text containers it is
 * placed on that container's text baseline — whose strut is taller than the
 * pill. Measured: the select row grew to 37px/38px instead of 36px and the
 * pill sat 6px/11px off-centre. The containers must drop their strut.
 */
it('keeps the vertical-alignment rules for pills used as select values', function (): void {
    $css = file_get_contents(__DIR__.'/../../resources/css/filament-model-link.css');

    foreach (['.fi-select-input-value-label:has(> .fi-badge)',
        '.fi-select-input-value-label:has(> .fi-pill-chain)',
        '.fi-badge-label:has(> .fi-badge)',
        '.fi-badge-label:has(> .fi-pill-chain)'] as $selector) {
        expect($css)->toContain($selector);
    }
});

/**
 * A hidden control is only acceptable where something else reveals it. On a
 * touch screen nothing does, so the rule must be gated on a real hover — and it
 * must not take the control out of the tab order, or the keyboard cannot reach
 * what only the mouse could discover.
 */
it('reveals the remove control of a pill chip on hover and focus, never on touch', function (): void {
    $css = file_get_contents(__DIR__.'/../../resources/css/filament-model-link.css');

    $chip = '.fi-select-input-value-badges-ctn .fi-badge:has(> .fi-badge-label-ctn .fi-badge)';

    expect($css)
        ->toContain('@media (hover: hover) {')
        ->toContain("{$chip} > .fi-badge-delete-btn {\n        opacity: 0;")
        ->toContain("{$chip}:hover > .fi-badge-delete-btn")
        ->toContain("{$chip}:focus-within > .fi-badge-delete-btn");

    // Hidden by opacity, which keeps the button in the layout and the tab order.
    $hide = substr($css, (int) strpos($css, '@media (hover: hover) {'), 600);
    expect($hide)->not->toContain('display: none')->not->toContain('visibility: hidden');
});

it('keeps an image icon to the size of an icon and hides what is hidden', function (): void {
    $css = file_get_contents(__DIR__.'/../../resources/css/filament-model-link.css');

    expect($css)
        ->toContain('.fi-pill-image {')
        ->toContain('.fi-pill-image-fallback {')
        // The author `display` values above would beat the user-agent's [hidden].
        ->toContain(".fi-pill-image-slot,\n.fi-pill-image-fallback {\n    display: contents;")
        ->toContain(".fi-pill-image[hidden],\n.fi-pill-image-fallback[hidden] {\n    display: none !important;");
});

/**
 * A frameless input is only acceptable where something reveals the frame —
 * hover, focus — and never where it hides an error. On touch nothing hovers,
 * so the frame must stay.
 */
it('drops the inline-edit frame only at rest, never on an error, never on touch', function (): void {
    $css = file_get_contents(__DIR__.'/../../resources/css/filament-model-link.css');

    $rule = '.fi-pill-inline-edit:not(:hover) .fi-input-wrp:not(:focus-within):not(.fi-invalid) {';
    $media = substr($css, (int) strrpos(substr($css, 0, (int) strpos($css, $rule)), '@media'), 40);

    expect($css)->toContain($rule)
        ->and($media)->toStartWith('@media (hover: hover) {');

    // Only the frame goes: no restated colors that would fight a theme.
    $start = (int) strpos($css, $rule);
    $block = substr($css, $start, (int) strpos($css, '}', $start) - $start);
    expect($block)->toContain('box-shadow: none;')
        ->toContain('background-color: transparent;')
        ->not->toContain('rgb(')
        ->not->toContain('--primary');
});
