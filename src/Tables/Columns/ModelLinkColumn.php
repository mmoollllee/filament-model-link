<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Tables\Columns;

use Closure;
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
 * Click-through is disabled on the cell so the embedded `<a>` inside the pill
 * stays clickable.
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
            ->disabledClick();

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

        if (! $related) {
            $placeholder = $this->getPlaceholder();

            return $placeholder
                ? '<div class="text-sm text-gray-400 dark:text-gray-500">'.e($placeholder).'</div>'
                : '';
        }

        $url = $this->buildUrl($record);
        $tooltip = $this->resolveTooltip($related);

        $tooltipAttr = $tooltip
            ? ' x-tooltip="{ content: '.e(json_encode($tooltip)).', theme: $store.theme }"'
            : '';

        return '<div'.$tooltipAttr.'>'.ModelReferencePresenter::renderPillChain($related, $url, $this->getCharacterLimit()).'</div>';
    }

    // ── Multi-Relation Rendering ──

    protected function renderMultiRelations(): string
    {
        $record = $this->getRecord();

        if (! $record) {
            return '';
        }

        $items = collect();
        foreach ($this->relationships as $rel) {
            $items = $items->merge($record->{$rel});
        }

        if ($items->isEmpty()) {
            return '';
        }

        // Collect models that already appear as a chain ancestor elsewhere;
        // skip them as standalone/chain targets to avoid rendering the same
        // entity twice (e.g. a team once as its own pill, once as a post's prefix).
        $ancestors = [];
        foreach ($items as $model) {
            $chain = ModelReferencePresenter::pillChainModels($model);
            array_pop($chain);
            foreach ($chain as $a) {
                $ancestors[$a::class.':'.$a->getKey()] = true;
            }
        }

        $html = '<div class="flex flex-wrap gap-1.5">';
        foreach ($items as $model) {
            $key = $model::class.':'.$model->getKey();
            if (isset($ancestors[$key])) {
                continue;
            }

            $html .= ModelReferencePresenter::renderPillChain($model, labelLimit: $this->getCharacterLimit() ?: 25);
        }
        $html .= '</div>';

        return $html;
    }

    // ── Internal ──

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
