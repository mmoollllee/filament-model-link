<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink;

use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Enums\IconSize;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\View\Components\BadgeComponent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mmoollllee\FilamentModelLink\Contracts\HasPillLabel;
use Mmoollllee\FilamentModelLink\Contracts\HasPillParent;
use Mmoollllee\FilamentModelLink\Contracts\HasPills;
use Throwable;

use function Filament\Support\generate_icon_html;

/**
 * Resolves labels, types, and URLs for related model references and renders
 * them as colored pills (single or chained).
 *
 * The presenter is intentionally framework-agnostic: project-specific concerns
 * (icon source, panel resolution, custom URL builders) are injected via the
 * `resolve*Using()` and `registerCustomUrlResolver()` hooks. Wire those up in
 * an application ServiceProvider.
 */
class ModelReferencePresenter
{
    /** @var Closure(string): (string|BackedEnum|null)|null */
    protected static ?Closure $iconResolver = null;

    /** @var Closure(Model): array{0: ?string, 1: ?string}|null Returns [resourceClass, panelId]. */
    protected static ?Closure $resourceResolver = null;

    /** @var Closure(Model, ?string): array<string, mixed>|null */
    protected static ?Closure $resourceParametersResolver = null;

    /** @var array<int, Closure(mixed, iterable<int, string>, mixed): ?string> */
    protected static array $customUrlResolvers = [];

    // ── Configuration hooks ───────────────────────────────────────────────

    /**
     * Provide an icon for a model class. Default: null (no icon).
     *
     * Example wiring in AppServiceProvider::boot():
     *
     *     ModelReferencePresenter::resolveIconUsing(fn (string $class) =>
     *         is_subclass_of($class, HasIcon::class) ? $class::iconName() : null
     *     );
     */
    public static function resolveIconUsing(?Closure $resolver): void
    {
        self::$iconResolver = $resolver;
    }

    /**
     * Override Filament resource resolution. Default scans all registered panels.
     *
     * The closure receives the related Model and must return [resourceClass, panelId].
     * Both may be null when no resource is found.
     */
    public static function resolveResourceUsing(?Closure $resolver): void
    {
        self::$resourceResolver = $resolver;
    }

    /**
     * Override the route parameter array passed to `Resource::getUrl()`.
     * Default returns `['record' => $related]`.
     */
    public static function resolveResourceParametersUsing(?Closure $resolver): void
    {
        self::$resourceParametersResolver = $resolver;
    }

    /**
     * Register a custom URL builder for specific model types. Resolvers are
     * called in registration order until one returns a non-null URL. Use this
     * to bypass standard resource resolution for models with nested routes.
     *
     * @param  Closure(mixed $related, iterable<int, string> $viewTypes, mixed $sourceRecord): ?string  $resolver
     */
    public static function registerCustomUrlResolver(Closure $resolver): void
    {
        self::$customUrlResolvers[] = $resolver;
    }

    /**
     * Wipe all configured hooks. Intended for tests.
     */
    public static function flush(): void
    {
        self::$iconResolver = null;
        self::$resourceResolver = null;
        self::$resourceParametersResolver = null;
        self::$customUrlResolvers = [];
        self::$iconCache = [];
        self::$resourceCache = [];
    }

    // ── Public API ────────────────────────────────────────────────────────

    public static function resolveRelatedRecord(mixed $record, string $name): mixed
    {
        if (! is_object($record)) {
            return null;
        }

        return $record->{self::relationship($name)} ?? null;
    }

    public static function displayLabel(mixed $record, string $name): ?string
    {
        $related = self::resolveRelatedRecord($record, $name);

        return $related
            ? self::displayLabelForRelated($record, $name, $related)
            : null;
    }

    public static function typeBasename(mixed $record, string $name): ?string
    {
        return self::typeBasenameForRelated(self::resolveRelatedRecord($record, $name));
    }

    public static function typeBasenameForRelated(mixed $related): ?string
    {
        return $related ? class_basename($related) : null;
    }

    /**
     * Default Filament resource actions tried when building a URL, in order.
     * The first action the current user can access wins. Configurable via
     * `config('filament-model-link.default_view_types')`; falls back to
     * `['view', 'edit']` when the config is missing or empty.
     *
     * @return array<int, string>
     */
    public static function defaultViewTypes(): array
    {
        $types = config('filament-model-link.default_view_types', ['view', 'edit']);

        return is_array($types) && $types !== [] ? array_values($types) : ['view', 'edit'];
    }

    /**
     * @param  iterable<int, string>|null  $viewTypes  Defaults to config `default_view_types`.
     */
    public static function url(mixed $record, string $name, ?iterable $viewTypes = null): ?string
    {
        return self::urlForRelated(self::resolveRelatedRecord($record, $name), $viewTypes, $record);
    }

    public static function labelForRelated(mixed $related): ?string
    {
        return $related instanceof Model ? self::basePillLabel($related) : null;
    }

    /**
     * @param  iterable<int, string>|null  $viewTypes  Defaults to config `default_view_types`.
     */
    public static function urlForRelated(
        mixed $related,
        ?iterable $viewTypes = null,
        mixed $sourceRecord = null,
    ): ?string {
        if (! $related) {
            return null;
        }

        // Resolve the default here — before custom resolvers run — so resolvers
        // always receive a concrete array, never null.
        $viewTypes ??= self::defaultViewTypes();

        foreach (self::$customUrlResolvers as $resolver) {
            $url = $resolver($related, $viewTypes, $sourceRecord);
            if ($url !== null) {
                return $url;
            }
        }

        if (! $related instanceof Model) {
            return null;
        }

        [$resource, $panel] = self::resolveResource($related);

        if (! $resource) {
            return null;
        }

        foreach ($viewTypes as $viewType) {
            if (! self::canAccessResourceAction($resource, $viewType, $related)) {
                continue;
            }

            try {
                return $resource::getUrl(
                    $viewType,
                    self::resourceParameters($related, $panel),
                    panel: $panel,
                );
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    // ── Rendering ─────────────────────────────────────────────────────────

    /**
     * Render a raw pill with no model behind it — for value pills like dates,
     * counters, or statuses that should look exactly like model pills.
     * Color falls back to the configured default; icon and link are optional.
     */
    public static function renderPill(
        string $label,
        ?string $color = null,
        string|BackedEnum|null $icon = null,
        ?string $url = null,
        string $iconTooltip = '',
    ): string {
        $classes = self::badgeClasses(filled($color) ? $color : self::defaultColor());

        return self::wrapInBadge($classes, self::iconSpan(self::renderIcon($icon), $iconTooltip).e($label), $url);
    }

    /**
     * Render a model as a full colored pill (outer `<span class="fi-badge fi-color-X">`)
     * with icon and label. Optionally wrapped in a link.
     *
     * Use this when the caller provides no surrounding badge of its own
     * (tables, form display components, free HTML, single-select dropdowns).
     * $color overrides the model's HasPills color — e.g. a per-record status.
     *
     * $stopClickPropagation: see [self::wrapInBadge()] — set it when the pill
     * sits inside a clickable cell, never inside a select dropdown.
     */
    public static function renderStandalonePill(mixed $model, string $label, ?string $url = null, ?string $color = null, bool $stopClickPropagation = false): string
    {
        $classes = self::badgeClasses(filled($color) ? $color : self::pillColor($model));

        return self::wrapInBadge($classes, self::iconHtml($model).e($label), $url, $stopClickPropagation);
    }

    /**
     * Render a model as a full pill where a caller-provided icon (with optional
     * tooltip) replaces the model's default icon. Useful for models whose
     * representative icon varies per record — e.g. a User shown with their
     * role's icon instead of the generic user icon.
     */
    public static function renderPillWithIconOverride(
        Model $model,
        string|BackedEnum|null $icon,
        string $label,
        string $iconTooltip = '',
        bool $linked = false,
        ?string $url = null,
        ?string $color = null,
    ): string {
        $classes = self::badgeClasses(filled($color) ? $color : self::pillColor($model));

        // A caller-supplied icon replaces the model's default; when none is
        // given, fall back to the default icon but keep the tooltip on it.
        $iconInner = ($icon === null || $icon === '')
            ? self::iconHtml($model)
            : self::renderIcon($icon);

        // No extra flex wrapper around icon + label: `.fi-badge` is already an
        // inline-flex row with its own gap, exactly like renderStandalonePill().
        $inner = self::iconSpan($iconInner, $iconTooltip).e($label);

        // An explicit $url (e.g. from Pill::url()) wins; otherwise resolve when linked.
        $url ??= $linked ? self::urlForRelated($model) : null;

        return self::wrapInBadge($classes, $inner, $url);
    }

    /**
     * Resolve the parent chain for a model via HasPillParent, returning
     * `[...ancestors, $related]`. Depth-capped via config `pill_chain_max_depth`.
     *
     * @return array<int, Model>
     */
    public static function pillChainModels(Model $related): array
    {
        $chain = [];
        $cursor = $related;
        $depth = 0;
        // At least 1 so the model itself is always rendered; the cap limits ancestors.
        $max = max(1, (int) (config('filament-model-link.pill_chain_max_depth') ?? 4));

        while ($cursor !== null && $depth++ < $max) {
            array_unshift($chain, $cursor);
            $cursor = $cursor instanceof HasPillParent ? $cursor->pillParent() : null;
        }

        return $chain;
    }

    /**
     * Plain-text equivalent of `renderPillChain` — walks the parent chain and
     * joins each model's `basePillLabel` with $separator. Suitable for
     * text-only contexts like filter indicators where HTML isn't allowed.
     */
    public static function textChain(Model $related, string $separator = ' › '): string
    {
        return implode(
            $separator,
            array_map(fn (Model $m): string => self::basePillLabel($m), self::pillChainModels($related)),
        );
    }

    /**
     * Render a model as a pill, automatically prepending pills for each parent
     * in its HasPillParent chain. A chain of two or more models becomes a
     * single combined chip with multiple color segments (flat inner edges,
     * rounded outer); a chain of one collapses to a plain standalone pill.
     *
     * Label limits: $labelLimit truncates the **target** (last) label;
     * $ancestorLabelLimit truncates every ancestor label (default from config).
     * Ancestors are truncated by default to keep the combined chip compact —
     * full names remain reachable via the ancestor's link.
     *
     * $stopClickPropagation: see [self::wrapInBadge()] — set it when the chain
     * sits inside a clickable cell, never inside a select dropdown.
     */
    public static function renderPillChain(
        Model $related,
        ?string $url = null,
        ?int $labelLimit = null,
        ?int $ancestorLabelLimit = null,
        ?string $targetColor = null,
        bool $stopClickPropagation = false,
    ): string {
        $ancestorLabelLimit ??= (int) (config('filament-model-link.ancestor_label_limit') ?? 20);

        $chain = self::pillChainModels($related);
        $lastIndex = count($chain) - 1;
        $targetLabel = self::basePillLabel($chain[$lastIndex]);
        if ($labelLimit !== null) {
            $targetLabel = Str::limit($targetLabel, $labelLimit);
        }

        if (count($chain) === 1) {
            return self::renderStandalonePill($chain[0], $targetLabel, $url ?? self::urlForRelated($chain[0]), $targetColor, $stopClickPropagation);
        }

        $segments = [];
        foreach ($chain as $i => $model) {
            $isTarget = $i === $lastIndex;
            $label = $isTarget
                ? $targetLabel
                : Str::limit(self::basePillLabel($model), $ancestorLabelLimit);

            $segmentUrl = ($isTarget && $url !== null) ? $url : self::urlForRelated($model);

            $segments[] = self::renderStandalonePill($model, $label, $segmentUrl, $isTarget ? $targetColor : null, $stopClickPropagation);
        }

        // Hook class only — the layout lives in the package stylesheet. Tailwind
        // utilities cannot be emitted from here: this file sits in the consumer's
        // vendor/, which their Tailwind build does not scan, so any utility not
        // coincidentally used elsewhere in their app is never generated.
        return '<span class="fi-pill-chain">'.implode('', $segments).'</span>';
    }

    /**
     * Render many models as pill chains inside a single flex-wrap container.
     *
     * Accepts any iterable (Eloquent Collection, array, …). Entries that are
     * not Eloquent models are skipped, exact duplicates (same class + key)
     * render once, and models that already appear as a chain ancestor of
     * another entry are dropped as standalone targets — their ancestor pill
     * already represents them.
     *
     * $maxPills caps how many chains render; the remainder collapses into a
     * "+N" overflow pill whose title lists the hidden labels. Non-positive
     * values mean unlimited. Returns '' when nothing is renderable, so
     * callers can distinguish "empty" without parsing HTML.
     *
     * Per-segment URLs resolve via `urlForRelated()` with the default view
     * types — pass individually rendered chains when per-call view types are
     * required.
     *
     * $stopClickPropagation: see [self::wrapInBadge()] — set it when the chains
     * sit inside a clickable cell, never inside a select dropdown.
     *
     * @param  iterable<int, mixed>  $models
     */
    public static function renderPillChains(
        iterable $models,
        ?int $labelLimit = null,
        ?int $maxPills = null,
        bool $stopClickPropagation = false,
    ): string {
        $unique = [];
        foreach ($models as $model) {
            if ($model instanceof Model) {
                $unique[$model::class.':'.$model->getKey()] ??= $model;
            }
        }

        if ($unique === []) {
            return '';
        }

        $ancestors = [];
        foreach ($unique as $model) {
            $chain = self::pillChainModels($model);
            array_pop($chain);
            foreach ($chain as $ancestor) {
                $ancestors[$ancestor::class.':'.$ancestor->getKey()] = true;
            }
        }

        $targets = [];
        foreach ($unique as $key => $model) {
            if (! isset($ancestors[$key])) {
                $targets[] = $model;
            }
        }

        // A pathological parent cycle can mark every entry as an ancestor;
        // rendering nothing would hide real data, so fall back to all entries.
        if ($targets === []) {
            $targets = array_values($unique);
        }

        if ($maxPills !== null && $maxPills < 1) {
            $maxPills = null;
        }

        $overflow = [];
        if ($maxPills !== null && count($targets) > $maxPills) {
            $overflow = array_slice($targets, $maxPills);
            $targets = array_slice($targets, 0, $maxPills);
        }

        // Hook class only — see renderPillChain() on why no Tailwind here.
        $html = '<div class="fi-pill-chains">';
        foreach ($targets as $model) {
            $html .= self::renderPillChain($model, labelLimit: $labelLimit, stopClickPropagation: $stopClickPropagation);
        }

        if ($overflow !== []) {
            $hidden = implode(', ', array_map(
                fn (Model $model): string => self::basePillLabel($model),
                $overflow,
            ));

            $html .= '<span class="'.self::badgeClasses(self::defaultColor()).'" title="'.e($hidden).'">+'.count($overflow).'</span>';
        }

        return $html.'</div>';
    }

    /**
     * Plain-text label for a single model, with no parent context appended.
     * `labelForRelated()` delegates here; `textChain()` joins multiple calls
     * for chain-style plain-text output.
     */
    public static function basePillLabel(Model $model): string
    {
        if ($model instanceof HasPillLabel) {
            return $model->pillLabel();
        }

        $label = $model->name ?? $model->title ?? null;

        if (filled($label)) {
            return (string) $label;
        }

        $type = $model instanceof HasPills ? $model::label() : class_basename($model);

        return $type.' #'.$model->getKey();
    }

    /**
     * Plain-text labels for a collection of models, keyed the same way as
     * `modelSelectOptions()`.
     *
     * This is the counterpart for the SELECTED values of a `->multiple()`
     * Select. Filament renders every selected value inside its own chip, so a
     * full pill there nests a badge in a badge. Feed pills to the dropdown
     * (`options()` / `getSearchResultsUsing()`) and these labels to
     * `getOptionLabelsUsing()` / `getOptionLabelUsing()`.
     *
     * @param  Collection<int, Model>  $models
     * @return array<int|string, string>
     */
    public static function modelSelectLabels(
        Collection $models,
        ?string $labelAttribute = null,
        ?callable $labelCallback = null,
    ): array {
        return $models->mapWithKeys(fn (Model $model): array => [
            $model->getKey() => self::selectLabelFor($model, $labelAttribute, $labelCallback),
        ])->all();
    }

    /**
     * Build HTML select options (full colored pills) from a collection of models.
     *
     * Pass $linked = true to wrap every pill in an `<a>` pointing at the
     * model's resource (resolved via `urlForRelated()`); options for models
     * without a resolvable URL silently fall back to plain pills.
     *
     * NOTE for `->multiple()` Selects: use this for the DROPDOWN only. Filament
     * wraps each selected value in its own chip, so returning a pill from
     * `getOptionLabelsUsing()` / `getOptionLabelFromRecordUsing()` renders a
     * badge inside a badge. Pair it with `modelSelectLabels()` there.
     *
     * @param  Collection<int, Model>  $models
     * @return array<int|string, string>
     */
    public static function modelSelectOptions(
        Collection $models,
        ?string $labelAttribute = null,
        ?callable $labelCallback = null,
        bool $linked = false,
    ): array {
        return $models->mapWithKeys(function (Model $model) use ($labelAttribute, $labelCallback, $linked): array {
            $label = self::selectLabelFor($model, $labelAttribute, $labelCallback);
            $url = $linked ? self::urlForRelated($model) : null;

            return [$model->getKey() => self::renderStandalonePill($model, $label, $url)];
        })->all();
    }

    /**
     * Shared label resolution for both select helpers: an explicit callback
     * wins, then an explicit attribute, then `basePillLabel()` — which honors
     * `HasPillLabel` and falls back to name/title, so a select label never
     * disagrees with the same model's pill elsewhere.
     */
    protected static function selectLabelFor(
        Model $model,
        ?string $labelAttribute,
        ?callable $labelCallback,
    ): string {
        return match (true) {
            $labelCallback !== null => (string) $labelCallback($model),
            $labelAttribute !== null => (string) $model->{$labelAttribute},
            default => self::basePillLabel($model),
        };
    }

    /**
     * Render a backed enum case as a colored pill, using HasColor/HasIcon/HasLabel contracts.
     */
    public static function renderEnumOption(BackedEnum $case, ?string $label = null): string
    {
        $label ??= $case instanceof HasLabel ? (string) $case->getLabel() : $case->value;

        $color = $case instanceof HasColor ? $case->getColor() : null;
        if (is_array($color)) {
            // HasColor may return a shade-keyed Color array ([50 => …, 600 => …]);
            // a numeric first key is a shade, not a usable palette name — discard it.
            $firstKey = array_key_first($color);
            $color = is_string($firstKey) ? $firstKey : null;
        }
        $color = is_string($color) && $color !== '' ? $color : self::defaultColor();

        $iconHtml = $case instanceof HasIcon ? self::renderIcon($case->getIcon()) : '';

        return self::wrapInBadge(self::badgeClasses($color), $iconHtml.e($label), null);
    }

    /**
     * Build HTML select options from a BackedEnum class.
     *
     * @param  class-string<BackedEnum>  $enumClass
     * @return array<int|string, string>
     */
    public static function enumSelectOptions(string $enumClass): array
    {
        return collect($enumClass::cases())
            ->mapWithKeys(fn (BackedEnum $case) => [$case->value => self::renderEnumOption($case)])
            ->all();
    }

    // ── Internals ─────────────────────────────────────────────────────────

    protected static function relationship(string $name): string
    {
        return Str::before($name, '.');
    }

    protected static function attribute(string $name): ?string
    {
        $attribute = Str::after($name, '.');

        return $attribute === $name ? null : $attribute;
    }

    /**
     * Resolve the pill color for a model via the HasPills contract.
     * Fallback: the configured default color.
     */
    protected static function pillColor(object|string|null $modelOrClass): string
    {
        if ($modelOrClass !== null) {
            $class = is_string($modelOrClass) ? $modelOrClass : $modelOrClass::class;

            if (is_subclass_of($class, HasPills::class)) {
                /** @var class-string<HasPills> $class */
                return $class::color();
            }
        }

        return self::defaultColor();
    }

    protected static function defaultColor(): string
    {
        return (string) (config('filament-model-link.default_color') ?? 'gray');
    }

    /**
     * Throwables count as "not authorized" — a broken policy setup must never
     * produce a falsely built link.
     *
     * @param  class-string  $resource
     */
    protected static function canAccessResourceAction(string $resource, string $viewType, Model $related): bool
    {
        try {
            return match ($viewType) {
                'view' => $resource::canView($related),
                'edit' => $resource::canEdit($related),
                'create' => $resource::canCreate(),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    protected static function displayLabelForRelated(mixed $record, string $name, mixed $related): ?string
    {
        $attribute = self::attribute($name);
        // For a dotless reference, defer to labelForRelated() so HasPillLabel is
        // honored instead of reading a raw `name` that may not be the pill label.
        $label = $attribute ? data_get($record, $name) : null;

        if ($label === null || $label === '') {
            $label = self::labelForRelated($related);
        }

        if ($label === null || $label === '') {
            return null;
        }

        return (string) $label;
    }

    /**
     * Resolve [resourceClass, panelId] for a model. Memoized per (current panel,
     * model class) for the request — the same class is re-resolved once per row,
     * dropdown option, and chain segment otherwise.
     *
     * @return array{0: mixed, 1: ?string}
     */
    protected static function resolveResource(mixed $related): array
    {
        if (! is_object($related)) {
            return [null, null];
        }

        try {
            $panelContext = Filament::getCurrentPanel()?->getId() ?? '';
        } catch (Throwable) {
            $panelContext = '';
        }

        return self::$resourceCache[$panelContext.'|'.$related::class] ??= self::computeResource($related);
    }

    /**
     * @return array{0: mixed, 1: ?string}
     */
    private static function computeResource(mixed $related): array
    {
        if (self::$resourceResolver !== null) {
            return (self::$resourceResolver)($related);
        }

        // Filament may not be booted (e.g. running outside a panel context,
        // queue jobs, or testbench tests without a PanelProvider). Any failure
        // here is "no resource" — never a hard error.
        try {
            $resources = Filament::getResources();
        } catch (Throwable) {
            return [null, null];
        }

        $resource = collect($resources)->first(
            fn ($candidate) => $related instanceof ($candidate::getModel())
        );

        if ($resource) {
            return [$resource, null];
        }

        foreach (Filament::getPanels() as $candidatePanel) {
            $resource = collect($candidatePanel->getResources())->first(
                fn ($candidate) => $related instanceof ($candidate::getModel())
            );

            if ($resource) {
                return [$resource, $candidatePanel->getId()];
            }
        }

        return [null, null];
    }

    /** @var array<string, array{0: mixed, 1: ?string}> */
    protected static array $resourceCache = [];

    /**
     * @return array<string, mixed>
     */
    protected static function resourceParameters(mixed $related, ?string $panel): array
    {
        if (self::$resourceParametersResolver !== null) {
            return (self::$resourceParametersResolver)($related, $panel);
        }

        return ['record' => $related];
    }

    /**
     * The class list for a pill, delegated to Filament's own badge resolver so
     * a pill is byte-identical to a badge Filament renders itself.
     *
     * Hand-writing `fi-color fi-color-X` is not enough and was a real bug:
     * `.fi-badge.fi-color` sets `color: var(--text)`, and `--text` is set
     * *only* by the `fi-text-color-{shade}` classes, which Filament derives per
     * color from WCAG contrast against the palette. Without them the
     * declaration is invalid at computed-value time and the pill's text falls
     * back to the inherited color — so the same report type rendered one color
     * in a table badge and another in a select pill.
     *
     * The resolved list is merged with `fi-color fi-color-X` rather than
     * replacing it: Filament returns an empty list for its default gray, and
     * both this package's stylesheet and consumer CSS key on `fi-color-X` as a
     * hook. Keeping it costs nothing — for every other color Filament already
     * includes both classes, so the merge deduplicates to exactly its output.
     */
    /**
     * The span that carries an icon's tooltip. Returns '' for a blank icon —
     * without that guard an empty span still occupies `.fi-badge`'s column gap
     * and hangs a `title` on a zero-width box nobody can hover.
     */
    protected static function iconSpan(string $iconHtml, string $tooltip): string
    {
        if ($iconHtml === '') {
            return '';
        }

        return '<span class="fi-pill-icon"'
            .($tooltip !== '' ? ' title="'.e($tooltip).'"' : '')
            .'>'.$iconHtml.'</span>';
    }

    protected static function badgeClasses(string $color): string
    {
        try {
            $resolved = FilamentColor::getComponentClasses(BadgeComponent::class, $color);
        } catch (Throwable) {
            $resolved = [];
        }

        $classes = array_unique(array_merge(
            ['fi-badge', 'fi-size-sm', 'fi-color', 'fi-color-'.$color],
            $resolved,
        ));

        return e(implode(' ', $classes));
    }

    /**
     * Wrap pill inner-HTML in a badge `<span>`, or an `<a>` when a safe URL is
     * given. Centralizes the badge markup so the link/span shape stays in sync.
     *
     * $stopClickPropagation keeps the link navigable inside a clickable cell:
     * Filament wraps a column that has an action in a button carrying
     * `wire:click.prevent.stop`, whose `preventDefault()` would otherwise
     * cancel the anchor's navigation. It is opt-in because in a Filament
     * select the click MUST reach the dropdown's own handler — stopping it
     * there would navigate away instead of picking the option.
     */
    protected static function wrapInBadge(string $classes, string $inner, ?string $url, bool $stopClickPropagation = false): string
    {
        $href = self::safeHref($url);

        if ($href !== null) {
            // `fi-pill-link` marks a pill whose href is real, so the stylesheet
            // can give it hover feedback — and withhold it where Filament
            // intercepts the click (dropdown options).
            //
            // `fi-pill-clickthrough` is the stylesheet's counterpart to the
            // stop modifier: it keeps the pill's pointer events inside a
            // select's value container, where they are neutralised by default.
            // A dropdown list item suppresses them either way, so the same
            // label can serve as a selectable option and as a navigable chip.
            $stop = $stopClickPropagation ? ' x-on:click.stop' : '';
            $clickthrough = $stopClickPropagation ? ' fi-pill-clickthrough' : '';

            return '<a href="'.e($href).'"'.$stop.' class="'.$classes.' fi-pill-link'.$clickthrough.'">'.$inner.'</a>';
        }

        return '<span class="'.$classes.'">'.$inner.'</span>';
    }

    /**
     * Permit only relative URLs and http(s) hrefs. Rejects `javascript:`,
     * `data:`, `vbscript:` and any other scheme so a custom URL resolver or a
     * route parameter cannot inject an executable href into a rendered pill.
     */
    protected static function safeHref(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $trimmed = ltrim($url);

        // No URI scheme (relative path, query, or fragment) → safe.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $trimmed) !== 1) {
            return $url;
        }

        // Has a scheme: allow only http/https.
        return preg_match('#^https?:#i', $trimmed) === 1 ? $url : null;
    }

    protected static function iconHtml(mixed $model): string
    {
        // renderIcon() already returns '' for blank icons and swallows failures.
        return self::renderIcon(self::resolveIconFor($model));
    }

    protected static function resolveIconFor(mixed $model): string|BackedEnum|null
    {
        if ($model === null) {
            return null;
        }

        $class = is_string($model) ? $model : $model::class;

        if (self::$iconResolver !== null) {
            return (self::$iconResolver)($class);
        }

        return null;
    }

    /**
     * Render an icon via Filament's icon pipeline, memoized per (icon, size) pair for the request.
     * Heavy callers (dropdowns with many pills) hit this repeatedly with the same args.
     */
    protected static function renderIcon(string|BackedEnum|null $icon, IconSize $size = IconSize::Small): string
    {
        if (blank($icon)) {
            return '';
        }

        $cacheKey = ($icon instanceof BackedEnum ? $icon->value : $icon).'|'.$size->value;
        if (isset(self::$iconCache[$cacheKey])) {
            return self::$iconCache[$cacheKey];
        }

        try {
            return self::$iconCache[$cacheKey] = (string) generate_icon_html($icon, size: $size)?->toHtml();
        } catch (Throwable) {
            // Don't memoize a transient failure — a later call may succeed.
            return '';
        }
    }

    /** @var array<string, string> */
    protected static array $iconCache = [];
}
