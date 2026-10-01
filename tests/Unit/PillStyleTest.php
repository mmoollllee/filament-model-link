<?php

declare(strict_types=1);

use Mmoollllee\FilamentModelLink\FilamentModelLink;
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;
use Mmoollllee\FilamentModelLink\Pill;
use Mmoollllee\FilamentModelLink\PillStyle;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\PillModel;

/**
 * Filament renders an icon string that contains a slash as an `<img>` — which
 * makes it the one icon the test environment CAN render (it has no heroicons
 * package). It stands in for "the type icon" wherever a test needs one.
 */
const TYPE_ICON = '/icons/type.svg';

// ── Per-record style ────────────────────────────────────────────────────

it('keeps the class-level look when no style resolver is registered', function (): void {
    $html = ModelReferencePresenter::renderStandalonePill(PillModel::fake(['name' => 'Acme']), 'Acme');

    expect($html)->toContain('fi-color-gray')->not->toContain('<img');
});

it('takes color and image from the record style', function (): void {
    FilamentModelLink::configure()->resolveStyleUsing(
        fn (PillModel $record): PillStyle => PillStyle::make(color: 'danger', image: 'https://example.test/favicon.ico'),
    );

    $html = ModelReferencePresenter::renderStandalonePill(PillModel::fake(['name' => 'Acme']), 'Acme');

    expect($html)
        ->toContain('fi-color-danger')
        ->toContain('src="https://example.test/favicon.ico"');
});

it('falls back to the class-level look field by field', function (): void {
    // The style speaks only about the image — the color must stay the class's.
    FilamentModelLink::configure()->resolveStyleUsing(
        fn (): PillStyle => PillStyle::make(image: 'https://example.test/favicon.ico'),
    );

    expect(ModelReferencePresenter::renderStandalonePill(PillModel::fake(['name' => 'Acme']), 'Acme'))
        ->toContain('fi-color-gray')
        ->toContain('fi-pill-image');
});

it('lets a style icon replace the class-level icon', function (): void {
    FilamentModelLink::configure()
        ->resolveIconUsing(fn (): string => '/icons/class.svg')
        ->resolveStyleUsing(fn (): PillStyle => PillStyle::make(icon: '/icons/record.svg'));

    expect(ModelReferencePresenter::renderStandalonePill(PillModel::fake(['name' => 'Acme']), 'Acme'))
        ->toContain('/icons/record.svg')
        ->not->toContain('/icons/class.svg');
});

it('resolves the style per record, not per class', function (): void {
    FilamentModelLink::configure()->resolveStyleUsing(
        fn (PillModel $record): ?PillStyle => $record->name === 'Acme' ? PillStyle::make(color: 'danger') : null,
    );

    expect(ModelReferencePresenter::renderStandalonePill(PillModel::fake(['name' => 'Acme']), 'Acme'))
        ->toContain('fi-color-danger')
        ->and(ModelReferencePresenter::renderStandalonePill(PillModel::fake(['name' => 'Other'], id: 2), 'Other'))
        ->toContain('fi-color-gray')
        ->not->toContain('fi-color-danger');
});

it('lets an explicit color beat the record style', function (): void {
    FilamentModelLink::configure()->resolveStyleUsing(fn (): PillStyle => PillStyle::make(color: 'danger'));

    $model = PillModel::fake(['name' => 'Acme']);

    expect(ModelReferencePresenter::renderStandalonePill($model, 'Acme', color: 'warning'))
        ->toContain('fi-color-warning')
        ->not->toContain('fi-color-danger')
        ->and(Pill::for($model)->color('success')->toHtml())
        ->toContain('fi-color-success')
        ->not->toContain('fi-color-danger');
});

it('gives every chain segment its own style', function (): void {
    FilamentModelLink::configure()->resolveStyleUsing(
        fn (PillModel $record): ?PillStyle => PillStyle::make(color: $record->name === 'Customer' ? 'info' : 'danger'),
    );

    $customer = PillModel::fake(['name' => 'Customer'], id: 1);
    $project = PillModel::fake(['name' => 'Project'], id: 2);
    $project->parent = $customer;

    $html = ModelReferencePresenter::renderPillChain($project);

    // The ancestor is a customer and looks like one, whatever it is the parent of.
    expect($html)->toContain('fi-color-info')->toContain('fi-color-danger');
});

it('rejects a style resolver that returns something else', function (): void {
    FilamentModelLink::configure()->resolveStyleUsing(fn (): array => ['color' => 'danger']);

    ModelReferencePresenter::renderStandalonePill(PillModel::fake(['name' => 'Acme']), 'Acme');
})->throws(UnexpectedValueException::class, 'must return a '.PillStyle::class);

it('forgets the style resolver on flush', function (): void {
    FilamentModelLink::configure()->resolveStyleUsing(fn (): PillStyle => PillStyle::make(color: 'danger'));
    FilamentModelLink::flush();

    expect(ModelReferencePresenter::renderStandalonePill(PillModel::fake(['name' => 'Acme']), 'Acme'))
        ->not->toContain('fi-color-danger');
});

it('applies the style to a pill that goes through the icon override', function (): void {
    FilamentModelLink::configure()->resolveStyleUsing(fn (): PillStyle => PillStyle::make(color: 'danger'));

    expect(Pill::for(PillModel::fake(['name' => 'Acme']))->icon(TYPE_ICON)->iconTooltip('Role')->toHtml())
        ->toContain('fi-color-danger');
});

// ── Free colors ─────────────────────────────────────────────────────────

it('renders a free color through inline shades instead of a palette class', function (): void {
    $html = ModelReferencePresenter::renderPill('Acme', '#0ea5e9');

    expect($html)
        ->toContain('fi-color-custom')
        // The shades travel with the pill: a color that exists on one record is
        // not in the page head, where Filament emits its palette.
        ->toContain('style="--color-50: oklch(')
        ->toContain('--color-950: oklch(')
        // The text shade is Filament's own contrast pick, light and dark.
        ->toContain('--text: var(--color-')
        ->toContain('--dark-text: var(--color-')
        ->not->toContain('fi-color-#');
});

it('keeps a palette name on classes only', function (): void {
    expect(ModelReferencePresenter::renderPill('Acme', 'danger'))
        ->toContain('fi-color-danger')
        ->not->toContain('style=');
});

it('treats every spelling of one free color alike', function (): void {
    $hex = ModelReferencePresenter::renderPill('Acme', '#00aaee');

    expect(ModelReferencePresenter::renderPill('Acme', '#0AE'))->toBe($hex)
        ->and(ModelReferencePresenter::renderPill('Acme', 'rgb(0, 170, 238)'))->toBe($hex);
});

it('falls back to the default color for something that is no color', function (): void {
    foreach (['not set', '#12', '#zzzzzz', 'red; background:url(x)'] as $junk) {
        expect(ModelReferencePresenter::renderPill('Acme', $junk))
            ->toContain('fi-color-gray')
            ->not->toContain('style=')
            ->not->toContain('fi-color-custom');
    }
});

it('takes a free color from the builder, the style and the chain target', function (): void {
    $model = PillModel::fake(['name' => 'Acme']);

    FilamentModelLink::configure()->resolveStyleUsing(fn (): PillStyle => PillStyle::make(color: '#0ea5e9'));

    expect(Pill::for($model)->toHtml())->toContain('fi-color-custom')->toContain('--color-500')
        ->and(Pill::for($model)->color('danger')->toHtml())->toContain('fi-color-danger')->not->toContain('style=');

    FilamentModelLink::flush();

    expect(Pill::for($model)->color('#0ea5e9')->toHtml())->toContain('fi-color-custom')
        ->and(Pill::make('Raw')->color('#0ea5e9')->toHtml())->toContain('fi-color-custom');
});

it('puts the free color on a linked pill as well', function (): void {
    $html = ModelReferencePresenter::renderPill('Acme', '#0ea5e9', url: '/x');

    expect($html)->toStartWith('<a href="/x"')->toContain('fi-pill-link')->toContain('style="--color-50');
});

// ── Image icons ─────────────────────────────────────────────────────────

it('renders an image in the icon slot', function (): void {
    $html = ModelReferencePresenter::renderPill('Acme', image: 'https://example.test/favicon.ico');

    expect($html)
        ->toContain('<span class="fi-pill-icon"><span class="fi-pill-image-slot" x-data="{ failed: false }"><img class="fi-pill-image" src="https://example.test/favicon.ico"')
        // Decorative: the label follows it.
        ->toContain('alt=""')
        ->toContain('loading="lazy"')
        // The favicon's host has no business learning which admin URL embedded it.
        ->toContain('referrerpolicy="no-referrer"');
});

it('keeps the type icon behind the image and swaps them when the image fails', function (): void {
    FilamentModelLink::configure()->resolveIconUsing(fn (): string => TYPE_ICON);

    $html = ModelReferencePresenter::renderStandalonePill(
        PillModel::fake(['name' => 'Acme']),
        'Acme',
    );
    expect($html)->not->toContain('fi-pill-image');

    FilamentModelLink::configure()->resolveStyleUsing(fn (): PillStyle => PillStyle::make(image: 'https://example.test/favicon.ico'));

    $html = ModelReferencePresenter::renderStandalonePill(PillModel::fake(['name' => 'Acme']), 'Acme');

    expect($html)
        // The failure is Alpine state on the slot, which a Livewire morph keeps
        // — a toggled attribute would be reset to the server's markup.
        ->toContain('<span class="fi-pill-image-slot" x-data="{ failed: false }">')
        ->toContain('x-bind:hidden="failed"')
        ->toContain('x-on:error="failed = true"')
        // A re-sorted row hands the slot another image: start over.
        ->toContain('x-on:load="failed = false"')
        // An image that failed before Alpine initialised.
        ->toContain('x-init="$el.decode().catch(() => failed = true)"')
        // The fallback is rendered but hidden until the image fails.
        ->toContain('<span class="fi-pill-image-fallback" hidden x-bind:hidden="! failed">')
        ->toContain(TYPE_ICON);
});

it('hides a failed image without a fallback when there is no type icon', function (): void {
    $html = ModelReferencePresenter::renderPill('Acme', image: 'https://example.test/favicon.ico');

    expect($html)
        ->toContain('x-on:error="failed = true"')
        ->toContain('x-bind:hidden="failed"')
        ->not->toContain('fi-pill-image-fallback');
});

it('uses the style icon as the image fallback', function (): void {
    FilamentModelLink::configure()
        ->resolveIconUsing(fn (): string => '/icons/class.svg')
        ->resolveStyleUsing(fn (): PillStyle => PillStyle::make(icon: '/icons/record.svg', image: 'https://example.test/favicon.ico'));

    $html = ModelReferencePresenter::renderStandalonePill(PillModel::fake(['name' => 'Acme']), 'Acme');

    expect($html)->toContain('fi-pill-image-fallback')->toContain('/icons/record.svg')->not->toContain('/icons/class.svg');
});

it('refuses an image source that could run code', function (string $unsafe): void {
    expect(ModelReferencePresenter::renderPill('Acme', image: $unsafe))->not->toContain('<img');
})->with([
    'javascript' => 'javascript:alert(1)',
    'vbscript' => 'vbscript:x',
    'html data URI' => 'data:text/html;base64,PHNjcmlwdD4=',
    'other scheme' => 'ftp://example.test/x.ico',
    'blank' => '   ',
    'empty' => '',
]);

it('renders an image source that is relative, http(s) or a data image', function (string $safe): void {
    expect(ModelReferencePresenter::renderPill('Acme', image: $safe))->toContain('<img');
})->with([
    'absolute path' => '/favicons/acme.png',
    'relative path' => 'favicons/acme.png',
    'https' => 'https://example.test/a.ico',
    'http' => 'http://example.test/a.ico',
    'png data URI' => 'data:image/png;base64,iVBORw0KGgo=',
    'svg data URI' => 'data:image/svg+xml;utf8,<svg/>',
]);

it('escapes the image source', function (): void {
    expect(ModelReferencePresenter::renderPill('Acme', image: 'https://example.test/a.ico?x="><script>'))
        ->not->toContain('"><script>')
        ->toContain('&quot;&gt;&lt;script&gt;');
});

it('lets the builder set an image that beats the record style and the icon', function (): void {
    FilamentModelLink::configure()->resolveStyleUsing(fn (): PillStyle => PillStyle::make(image: 'https://example.test/style.ico'));

    $model = PillModel::fake(['name' => 'Acme']);

    expect(Pill::for($model)->image('https://example.test/builder.ico')->toHtml())
        ->toContain('builder.ico')
        ->not->toContain('style.ico')
        ->and(Pill::for($model)->image('https://example.test/builder.ico')->icon(TYPE_ICON)->toHtml())
        // The explicit icon is the fallback now.
        ->toContain('builder.ico')
        ->toContain('fi-pill-image-fallback')
        ->toContain(TYPE_ICON)
        ->and(Pill::make('Raw')->image('https://example.test/raw.ico')->toHtml())
        ->toContain('raw.ico');
});

it('lets an explicit icon beat the style image', function (): void {
    FilamentModelLink::configure()->resolveStyleUsing(fn (): PillStyle => PillStyle::make(image: 'https://example.test/style.ico'));

    // The icon override says "show THIS instead of the default" — and the
    // style's image is part of the default.
    expect(Pill::for(PillModel::fake(['name' => 'Acme']))->icon(TYPE_ICON)->toHtml())
        ->toContain(TYPE_ICON)
        ->not->toContain('style.ico');
});

it('ignores a blank image', function (): void {
    expect(Pill::for(PillModel::fake(['name' => 'Acme']))->image('')->toHtml())->not->toContain('<img')
        ->and(Pill::for(PillModel::fake(['name' => 'Acme']))->image(null)->toHtml())->not->toContain('<img');
});
