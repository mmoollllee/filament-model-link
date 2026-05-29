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

        ['resource' => $resource, 'panel' => $panel] = self::resolveResource($related);

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
     * Render a model as a full colored pill (outer `<span class="fi-badge fi-color-X">`)
     * with icon and label. Optionally wrapped in a link.
     *
     * Use this when the caller provides no surrounding badge of its own
     * (tables, form display components, free HTML, single-select dropdowns).
     */
    public static function renderStandalonePill(mixed $model, string $label, ?string $url = null): string
    {
        $color = self::pillConfig($model)['color'];
        $classes = self::badgeClasses($color);
        $iconHtml = self::iconHtml($model);

        if ($url) {
            return '<a href="'.e($url).'" class="'.$classes.' transition hover:underline">'
                .$iconHtml.e($label).'</a>';
        }

        return '<span class="'.$classes.'">'.$iconHtml.e($label).'</span>';
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
    ): string {
        $color = self::pillConfig($model)['color'];
        $classes = self::badgeClasses($color);

        if ($icon === null || $icon === '') {
            $iconHtml = self::iconHtml($model);
        } else {
            $titleAttr = $iconTooltip !== '' ? ' title="'.e($iconTooltip).'"' : '';
            $iconHtml = '<span class="inline-flex"'.$titleAttr.'>'.self::renderIcon($icon).'</span>';
        }

        $inner = '<span class="inline-flex items-center gap-1.5">'
            .$iconHtml
            .e($label)
            .'</span>';

        $url = $linked ? self::urlForRelated($model) : null;

        if ($url) {
            return '<a href="'.e($url).'" class="'.$classes.' transition hover:underline">'.$inner.'</a>';
        }

        return '<span class="'.$classes.'">'.$inner.'</span>';
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
        $max = (int) (config('filament-model-link.pill_chain_max_depth') ?? 5);

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
     */
    public static function renderPillChain(
        Model $related,
        ?string $url = null,
        ?int $labelLimit = null,
        ?int $ancestorLabelLimit = null,
    ): string {
        $ancestorLabelLimit ??= (int) (config('filament-model-link.ancestor_label_limit') ?? 20);

        $chain = self::pillChainModels($related);
        $lastIndex = count($chain) - 1;
        $targetLabel = self::basePillLabel($chain[$lastIndex]);
        if ($labelLimit !== null) {
            $targetLabel = Str::limit($targetLabel, $labelLimit);
        }

        if (count($chain) === 1) {
            return self::renderStandalonePill($chain[0], $targetLabel, $url ?? self::urlForRelated($chain[0]));
        }

        $segments = [];
        foreach ($chain as $i => $model) {
            $isTarget = $i === $lastIndex;
            $label = $isTarget
                ? $targetLabel
                : Str::limit(self::basePillLabel($model), $ancestorLabelLimit);

            $segmentUrl = ($isTarget && $url !== null) ? $url : self::urlForRelated($model);

            $segments[] = self::renderStandalonePill($model, $label, $segmentUrl);
        }

        return '<span class="fi-pill-chain inline-flex items-stretch [&>*]:!rounded-none [&>*:first-child]:!rounded-s-md [&>*:last-child]:!rounded-e-md">'
            .implode('', $segments)
            .'</span>';
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

        return filled($label) ? (string) $label : class_basename($model).' #'.$model->getKey();
    }

    /**
     * Build HTML select options (full colored pills) from a collection of models.
     *
     * Pass $linked = true to wrap every pill in an `<a>` pointing at the
     * model's resource (resolved via `urlForRelated()`); options for models
     * without a resolvable URL silently fall back to plain pills.
     *
     * @param  Collection<int, Model>  $models
     * @return array<int|string, string>
     */
    public static function modelSelectOptions(
        Collection $models,
        string $labelAttribute = 'name',
        ?callable $labelCallback = null,
        bool $linked = false,
    ): array {
        return $models->mapWithKeys(function ($model) use ($labelAttribute, $labelCallback, $linked) {
            $label = $labelCallback ? $labelCallback($model) : (string) $model->{$labelAttribute};
            $url = $linked ? self::urlForRelated($model) : null;

            return [$model->getKey() => self::renderStandalonePill($model, $label, $url)];
        })->all();
    }

    /**
     * Render a backed enum case as a colored pill, using HasColor/HasIcon/HasLabel contracts.
     */
    public static function renderEnumOption(BackedEnum $case, ?string $label = null): string
    {
        $label ??= $case instanceof HasLabel ? (string) $case->getLabel() : $case->value;

        $color = $case instanceof HasColor ? $case->getColor() : null;
        if (is_array($color)) {
            $color = array_key_first($color) ?? null;
        }
        $color ??= (string) (config('filament-model-link.default_color') ?? 'gray');

        $iconHtml = $case instanceof HasIcon ? self::renderIcon($case->getIcon()) : '';

        return '<span class="'.self::badgeClasses($color).'">'.$iconHtml.e($label).'</span>';
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
     * Read display metadata (label + color) from the model's HasPills contract.
     * Fallback: class basename + configured default color.
     *
     * @return array{label: string, color: string}
     */
    protected static function pillConfig(object|string|null $modelOrClass): array
    {
        $defaultColor = (string) (config('filament-model-link.default_color') ?? 'gray');

        if ($modelOrClass === null) {
            return ['label' => '', 'color' => $defaultColor];
        }

        $class = is_string($modelOrClass) ? $modelOrClass : $modelOrClass::class;

        if (is_subclass_of($class, HasPills::class)) {
            /** @var class-string<HasPills> $class */
            return [
                'label' => $class::label(),
                'color' => $class::color(),
            ];
        }

        return ['label' => class_basename($class), 'color' => $defaultColor];
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
        $label = $attribute ? data_get($record, $name) : ($related->name ?? null);

        if ($label === null || $label === '') {
            $label = self::labelForRelated($related);
        }

        if ($label === null || $label === '') {
            return null;
        }

        return (string) $label;
    }

    /**
     * @return array{resource: mixed, panel: ?string}
     */
    protected static function resolveResource(mixed $related): array
    {
        if (self::$resourceResolver !== null) {
            [$resource, $panel] = (self::$resourceResolver)($related);

            return ['resource' => $resource, 'panel' => $panel];
        }

        // Filament may not be booted (e.g. running outside a panel context,
        // queue jobs, or testbench tests without a PanelProvider). Any failure
        // here is "no resource" — never a hard error.
        try {
            $resources = Filament::getResources();
        } catch (Throwable) {
            return ['resource' => null, 'panel' => null];
        }

        $resource = collect($resources)->first(
            fn ($candidate) => $related instanceof ($candidate::getModel())
        );

        $panel = null;

        if (! $resource) {
            foreach (Filament::getPanels() as $candidatePanel) {
                $resource = collect($candidatePanel->getResources())->first(
                    fn ($candidate) => $related instanceof ($candidate::getModel())
                );

                if ($resource) {
                    $panel = $candidatePanel->getId();
                    break;
                }
            }
        }

        return [
            'resource' => $resource,
            'panel' => $panel,
        ];
    }

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

    protected static function badgeClasses(string $color): string
    {
        return 'fi-badge fi-size-sm fi-color fi-color-'.e($color);
    }

    protected static function iconHtml(mixed $model): string
    {
        $icon = self::resolveIconFor($model);

        if (blank($icon)) {
            return '';
        }

        try {
            return self::renderIcon($icon);
        } catch (Throwable) {
            return '';
        }
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
            return self::$iconCache[$cacheKey] = '';
        }
    }

    /** @var array<string, string> */
    protected static array $iconCache = [];
}
