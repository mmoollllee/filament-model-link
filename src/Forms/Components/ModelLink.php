<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Forms\Components;

use Closure;
use Filament\Forms\Components\Placeholder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;

/**
 * Renders related models as colored pill chains (icon + label) with optional
 * links. Use inside Filament form schemas — typically in modal detail views
 * or `Infolist`-like Sections.
 *
 *     // To-one — a single pill chain:
 *     ModelLink::make('team.name')->label('Team')
 *
 *     // To-many — one pill chain per related model:
 *     ModelLink::make('tags.name')->label('Tags')->maxPills(6)
 *
 *     // Multiple relationships merged into one pill list:
 *     ModelLink::make('links')->relationships(['authors', 'posts'])
 *
 * Collection rendering resolves per-model URLs with the default view types;
 * `viewTypes()` applies to the to-one path.
 */
class ModelLink extends Placeholder
{
    /**
     * Null defers to config `default_view_types` at resolution time.
     *
     * @var iterable<int, string>|null
     */
    protected ?iterable $viewTypes = null;

    /** @var string[]|null */
    protected ?array $relationships = null;

    protected ?int $maxPills = null;

    /** @var (Closure(Model): ?string)|null */
    protected ?Closure $labelUsing = null;

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
     * from the given relationships — parity with `ModelLinkColumn`. The
     * component name then acts as a plain label and needs no matching relation.
     *
     * @param  string[]  $relationships
     */
    public function relationships(array $relationships): static
    {
        $this->relationships = $relationships;

        return $this;
    }

    /**
     * Cap the number of pill chains rendered; the remainder collapses into a
     * "+N" overflow pill listing the hidden labels in its title. Applies to
     * to-many and multi-relation rendering; a to-one reference is unaffected.
     */
    public function maxPills(?int $maxPills): static
    {
        $this->maxPills = $maxPills;

        return $this;
    }

    /**
     * Label every pill — ancestors in a chain included — with something else
     * than its `HasPillLabel` label; return null to keep that. Parity with
     * `ModelLinkColumn::labelUsing()`.
     *
     * @param  (Closure(Model): ?string)|null  $callback
     */
    public function labelUsing(?Closure $callback): static
    {
        $this->labelUsing = $callback;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->content(function ($record) {
            if (! $record) {
                return null;
            }

            if ($this->relationships !== null) {
                return $this->htmlOrNull(ModelReferencePresenter::renderPillChains(
                    $this->mergedRelated($record),
                    maxPills: $this->maxPills,
                    labelUsing: $this->labelUsing,
                ));
            }

            $related = ModelReferencePresenter::resolveRelatedRecord($record, $this->getName());

            // A to-many relation resolves to a collection — render one chain
            // per entry instead of feeding the collection to renderPillChain().
            if (is_iterable($related)) {
                return $this->htmlOrNull(ModelReferencePresenter::renderPillChains(
                    $related,
                    maxPills: $this->maxPills,
                    labelUsing: $this->labelUsing,
                ));
            }

            if (! $related) {
                return null;
            }

            return new HtmlString(ModelReferencePresenter::renderPillChain($related, $this->buildUrl($record), labelUsing: $this->labelUsing));
        });
    }

    protected function buildUrl(mixed $record): ?string
    {
        return ModelReferencePresenter::url($record, $this->getName(), $this->viewTypes);
    }

    /**
     * Merge every configured relationship into one flat collection. To-one
     * results are wrapped so they merge as models instead of being flattened
     * into their attribute arrays by `Collection::merge()`.
     *
     * @return Collection<int, mixed>
     */
    protected function mergedRelated(mixed $record): Collection
    {
        $items = collect();

        if (! is_object($record)) {
            return $items;
        }

        foreach ($this->relationships ?? [] as $relationship) {
            $related = $record->{$relationship} ?? null;
            $items = $items->merge(is_iterable($related) ? $related : [$related]);
        }

        return $items;
    }

    protected function htmlOrNull(string $html): ?HtmlString
    {
        return $html === '' ? null : new HtmlString($html);
    }
}
