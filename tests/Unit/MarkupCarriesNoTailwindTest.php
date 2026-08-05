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
    foreach (['fi-pill-chain', 'fi-pill-chains', 'fi-pill-link', 'fi-pill-clickthrough'] as $hook) {
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
