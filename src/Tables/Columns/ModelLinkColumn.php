<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Tables\Columns;

use Closure;
use Filament\Actions\Action;
use Filament\Support\Components\Contracts\HasEmbeddedView;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;

/**
 * Table column that renders related models as a pill chain (icon + linked title).
 *
 * Single-relation mode (default): shows pill chain for the model resolved via
 * the column name (e.g. `author.name` → renders the author, prefixed by its
 * HasPillParent chain).
 *
 * Multi-relation mode: pass `relationships(['rel1', 'rel2', ...])` to render
 * pill chains for every record across the listed relationships. Models that
 * appear as ancestors of others in the same set are de-duplicated.
 *
 * A reference that resolves to a collection (to-many relation) renders one
 * chain per entry automatically; `maxPills()` caps the rendered chains with a
 * "+N" overflow pill.
 *
 * Click-through is disabled on the cell so the embedded `<a>` inside the pill
 * stays clickable — unless the column gets a cell `action()`, which re-enables
 * it and lets the pills stop the click themselves (see `action()`).
 */
class ModelLinkColumn extends TextColumn implements HasEmbeddedView
{
    /**
     * Null defers to config `default_view_types` at resolution time.
     *
     * @var iterable<int, string>|null
     */
    protected ?iterable $viewTypes = null;

    protected ?string $overwriteName = null;

    /** @var string[]|null */
    protected ?array $relationships = null;

    protected ?int $maxPills = null;

    protected ?Closure $relatedTooltipResolver = null;

    public function overwriteName(string $name): static
    {
        $this->overwriteName = $name;

        return $this->initialize();
    }

    public function setViewType(string $viewType): static
    {
        $this->viewTypes = [$viewType];

        return $this;
    }

    /**
     * @param  iterable<int, string>  $viewTypes
     */
    public function viewTypes(iterable $viewTypes): static
    {
        $this->viewTypes = $viewTypes;

        return $this;
    }

    /**
     * Enable multi-relation mode: renders pill chains for all related models
     * from the given relationships.
     *
     * @param  string[]  $relationships
     */
    public function relationships(array $relationships): static
    {
        $this->relationships = $relationships;

        // Single-relation mode reads `state()` via the relation name. In
        // multi-relation mode the column name is usually a label like "links"
        // with no matching relation — rebuild the state from the merged labels
        // so search/sort still operate on something meaningful.
        $this->state(function ($record): ?string {
            if (! is_object($record)) {
                return null;
            }

            $labels = [];
            foreach ($this->relationships ?? [] as $rel) {
                $related = $record->{$rel} ?? [];
                foreach (is_iterable($related) ? $related : [$related] as $model) {
                    if ($model instanceof Model) {
                        $labels[] = ModelReferencePresenter::basePillLabel($model);
                    }
                }
            }

            return $labels === [] ? null : implode(', ', $labels);
        });

        return $this;
    }

    /**
     * Cap the number of pill chains rendered per cell; the remainder collapses
     * into a "+N" overflow pill listing the hidden labels in its title.
     * Applies to multi-relation mode and to-many references; a to-one
     * reference is unaffected.
     */
    public function maxPills(?int $maxPills): static
    {
        $this->maxPills = $maxPills;

        return $this;
    }

    /**
     * Tooltip closure receiving the *resolved related model* (not the column
     * state). Removes the boilerplate of fishing the related model out of
     * `$record` by hand:
     *
     *     ModelLinkColumn::make('author.name')
     *         ->relatedTooltip(fn (?Author $author) => $author?->summary());
     */
    public function relatedTooltip(?Closure $resolver): static
    {
        $this->relatedTooltipResolver = $resolver;

        return $this;
    }

    /**
     * A cell action makes Filament wrap the whole cell in a
     * `wire:click.prevent.stop` button, which `initialize()` suppresses via
     * `disabledClick()` so the embedded `<a>` keeps working. Re-enable the
     * click here and let the pills stop it themselves instead — clicking a
     * pill navigates, clicking anywhere else in the cell runs the action.
     */
    public function action(Closure|Action|string|null $action): static
    {
        return parent::action($action)->disabledClick($action === null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialize();
    }

    protected function initialize(): static
    {
        $this
            ->state(fn ($record): ?string => ModelReferencePresenter::displayLabel($record, $this->referenceName()))
            ->limit(25)
            ->separator('')
            // Keep an already configured cell action working: `overwriteName()`
            // re-runs this method, and an unconditional `disabledClick()` would
            // silently undo what `action()` set, depending on call order.
            ->disabledClick($this->getAction() === null);

        return $this;
    }

    // ── HasEmbeddedView ──

    public function toEmbeddedHtml(): string
    {
        if ($this->relationships) {
            return $this->renderMultiRelations();
        }

        return $this->renderSingleRelation();
    }

    // ── Single-Relation Rendering ──

    protected function renderSingleRelation(): string
    {
        $record = $this->getRecord();

        if (! $record) {
            return '';
        }

        $related = $this->resolveRelatedRecord($record);

        // A to-many relation resolves to a collection — render one chain per
        // entry instead of feeding the collection to renderPillChain().
        if (is_iterable($related)) {
            $html = ModelReferencePresenter::renderPillChains(
                $related,
                labelLimit: $this->getCharacterLimit() ?? 25,
                maxPills: $this->maxPills,
                stopClickPropagation: $this->shouldStopPillClickPropagation(),
            );

            return $html !== '' ? $html : $this->renderPlaceholder();
        }

        if (! $related) {
            return $this->renderPlaceholder();
        }

        $url = $this->buildUrl($record);
        $tooltip = $this->resolveTooltip($related);

        $tooltipAttr = $tooltip
            ? ' x-tooltip="{ content: '.e(json_encode($tooltip) ?: '""').', theme: $store.theme }"'
            : '';

        return '<div'.$tooltipAttr.'>'.ModelReferencePresenter::renderPillChain(
            $related,
            $url,
            $this->getCharacterLimit(),
            stopClickPropagation: $this->shouldStopPillClickPropagation(),
        ).'</div>';
    }

    protected function renderPlaceholder(): string
    {
        $placeholder = $this->getPlaceholder();

        // Hook class only — `text-sm text-gray-400 dark:text-gray-500` used to
        // sit here and never rendered: those utilities come from this package
        // under vendor/, which a consumer's Tailwind build does not scan.
        return $placeholder
            ? '<div class="fi-pill-placeholder">'.e($placeholder).'</div>'
            : '';
    }

    // ── Multi-Relation Rendering ──

    protected function renderMultiRelations(): string
    {
        $record = $this->getRecord();

        if (! $record) {
            return '';
        }

        // Wrap to-one results so Collection::merge() treats them as models
        // instead of flattening them into their attribute arrays.
        $items = collect();
        foreach ($this->relationships as $rel) {
            $related = $record->{$rel} ?? null;
            $items = $items->merge(is_iterable($related) ? $related : [$related]);
        }

        // De-duplication (exact + ancestor) happens inside renderPillChains().
        return ModelReferencePresenter::renderPillChains(
            $items,
            labelLimit: $this->getCharacterLimit() ?? 25,
            maxPills: $this->maxPills,
            stopClickPropagation: $this->shouldStopPillClickPropagation(),
        );
    }

    // ── Internal ──

    /**
     * Only a cell that actually reacts to clicks needs the pills to stop them:
     * a plain (or click-disabled) cell leaves the anchor alone anyway.
     */
    protected function shouldStopPillClickPropagation(): bool
    {
        return $this->getAction() !== null && ! $this->isClickDisabled();
    }

    protected function resolveTooltip(mixed $related): mixed
    {
        if ($this->relatedTooltipResolver !== null) {
            return ($this->relatedTooltipResolver)($related);
        }

        return $this->getTooltip($this->getState());
    }

    protected function resolveRelatedRecord(mixed $record): mixed
    {
        return ModelReferencePresenter::resolveRelatedRecord($record, $this->referenceName());
    }

    protected function buildUrl(mixed $record): ?string
    {
        return ModelReferencePresenter::url($record, $this->referenceName(), $this->viewTypes);
    }

    protected function referenceName(): string
    {
        return $this->overwriteName ?? $this->getName();
    }
}
