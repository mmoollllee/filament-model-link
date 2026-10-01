<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Forms;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;
use Mmoollllee\FilamentModelLink\Tables\Columns\MultiSelectColumn;

/**
 * Wires a `Select` (or a table `SelectColumn`) to render its models as pills —
 * the whole recipe in one call, because getting it right by hand means knowing
 * four unrelated things:
 *
 * 1. `allowHtml()` and `native(false)`, or the markup is escaped / unusable.
 * 2. BOTH label sources have to render the same pill. `select.js` fills its
 *    label repository from the OPTIONS array and only asks the server
 *    (`getOptionLabelsUsing`, fed by `getOptionLabelFromRecordUsing`) for values
 *    the options don't carry — e.g. a selected record beyond a `limit()`.
 * 3. A selected value's link only survives with click-through; in the dropdown
 *    the stylesheet suppresses it either way, so one markup serves both.
 * 4. The search has to run on the VISIBLE text. `select.js` filters a client-side
 *    options list by `option.label.toLowerCase().includes(query)` — and with
 *    `allowHtml()` that label is the pill's markup, so "a" matches every option
 *    (`<a href=…`, `class=…`) and the list never narrows. The search therefore
 *    runs on the server, against the label the user sees.
 *
 * @see Select::pillOptions() — the macro this class backs.
 * @see SelectColumn::pillOptions() — the same recipe for a table column.
 * @see MultiSelectColumn::pillOptions() — and for an inline multi-select cell.
 */
class PillSelect
{
    /**
     * Keyboard handling Filament's select does not have, attached to a
     * `->multiple()` pill select through `extraAlpineAttributes()`:
     *
     * - Backspace in an EMPTY search box removes the last assigned pill.
     * - Backspace / Delete on a focused pill removes that pill and hands the
     *   focus back to the field.
     *
     * Filament already covers the rest: the × of each chip is a real button that
     * Space and Enter activate, and a pill link is reachable with Tab.
     *
     * Rendered into an attribute WITHOUT escaping (Filament's contract for extra
     * Alpine attributes), so it uses single quotes only and no `&` or `<`.
     * `select` and `$el` are the Alpine component's own — the Select instance and
     * its root element.
     */
    public const KEYBOARD_HANDLER = <<<'JS'
        if (! ['Backspace', 'Delete'].includes($event.key) || ! select || select.isDisabled) return;
        const target = $event.target;
        if (target.matches('.fi-select-input-search-ctn input')) {
            const values = Array.isArray(select.state) ? select.state : [];
            if ($event.key !== 'Backspace' || target.value !== '' || values.length === 0) return;
            $event.preventDefault();
            select.selectOption(values[values.length - 1]);
            return;
        }
        const chip = target.closest('a.fi-pill-link')?.parentElement?.closest('.fi-select-input-value-badges-ctn .fi-badge');
        if (! chip) return;
        $event.preventDefault();
        chip.querySelector('.fi-badge-delete-btn')?.click();
        $el.querySelector('.fi-select-input-btn')?.focus();
        JS;

    /**
     * {@see self::KEYBOARD_HANDLER} on one line, ready for an attribute.
     */
    public static function keyboardHandler(): string
    {
        return (string) preg_replace('/\s+/', ' ', self::KEYBOARD_HANDLER);
    }

    /**
     * Narrow the object a macro closure is bound to. `Select::macro()` binds
     * `$this` to the select at call time, but static analysis only sees the
     * registering class — this is the one place that says so, loudly.
     */
    public static function select(object $select): Select
    {
        if (! $select instanceof Select) {
            throw new InvalidArgumentException('pillOptions() can only be called on a '.Select::class.'.');
        }

        return $select;
    }

    /**
     * The `SelectColumn` counterpart of {@see self::select()}.
     */
    public static function column(object $column): SelectColumn
    {
        if (! $column instanceof SelectColumn) {
            throw new InvalidArgumentException('pillOptions() can only be called on a '.Select::class.' or a '.SelectColumn::class.'.');
        }

        return $column;
    }

    /**
     * @param  iterable<int, Model>|(Closure(): iterable<int, Model>)|null  $models  Options
     *                                                                               source. Pass null on a `relationship()` select — Filament then builds the
     *                                                                               options from the related records itself.
     * @param  (Closure(Model): string)|null  $renderUsing  Renders a record's pill, for
     *                                                      flavors the presenter cannot infer — a per-record icon, a wrapper of your
     *                                                      own. Replaces the label/linked/clickthrough arguments and, like them,
     *                                                      serves BOTH label sources.
     * @param  bool|null  $searchable  null leaves Filament's default alone (a `->multiple()`
     *                                 select is searchable, a single one is not); true / false forces it.
     * @param  (Closure(string): iterable<int, Model>)|null  $searchUsing  Looks the records up
     *                                                                     for a search term — use it when the options are too many to
     *                                                                     hold in memory. Without it, the given models are filtered by their
     *                                                                     label. Takes Filament's injections, `$search` included.
     */
    public static function apply(
        Select $select,
        iterable|Closure|null $models = null,
        ?string $labelAttribute = null,
        ?Closure $labelCallback = null,
        bool $linked = true,
        bool $clickthrough = true,
        ?Closure $renderUsing = null,
        ?bool $searchable = null,
        ?Closure $searchUsing = null,
    ): Select {
        $render = self::renderer($labelAttribute, $labelCallback, $linked, $clickthrough, $renderUsing);

        $select
            ->allowHtml()
            ->native(false)
            // Covers the relationship case and, on a limited options list, every
            // selected record the options array does not contain.
            ->getOptionLabelFromRecordUsing($render);

        if ($searchable !== null) {
            $select->searchable($searchable);
        }

        if ($models !== null) {
            // The models closure runs through the component's own evaluator, so
            // it can take Filament's injections — `Get $get` to read a sibling
            // field, `$record` to keep the current value in a filtered list.
            $select->options(static fn (Select $component): array => self::renderModels(
                self::resolveModels($models instanceof Closure ? $component->evaluate($models) : $models),
                $render,
            ));
        }

        if ($models !== null || $searchUsing !== null) {
            $plainLabel = self::plainLabeler($labelAttribute, $labelCallback, $renderUsing, $render);

            $select->getSearchResultsUsing(static fn (Select $component, string $search): array => self::search(
                $component,
                $search,
                $models,
                $searchUsing,
                $render,
                $plainLabel,
            ));
        }

        // Backspace to remove the last pill is a multi-select idiom; evaluated
        // lazily because `->multiple()` may be called after the macro.
        $select->extraAlpineAttributes(
            static fn (Select $component): array => $component->isMultiple()
                ? ['x-on:keydown' => self::keyboardHandler()]
                : [],
            merge: true,
        );

        return $select;
    }

    /**
     * The same recipe for a table `SelectColumn`: a to-one assignment that is
     * edited in the cell, with the pill as the selected value and in the list.
     *
     * Filament's `SelectColumn` holds ONE value — it has no multiple mode — so
     * this column is for to-one assignments. A to-many cell is a
     * {@see MultiSelectColumn}.
     *
     * @param  iterable<int, Model>|(Closure(): iterable<int, Model>)|null  $models
     * @param  (Closure(Model): string)|null  $renderUsing
     * @param  bool|null  $searchable  null leaves the column as it is, true / false forces
     *                                 `searchableOptions()`.
     * @param  (Closure(string): iterable<int, Model>)|null  $searchUsing
     */
    public static function applyToColumn(
        SelectColumn $column,
        iterable|Closure|null $models = null,
        ?string $labelAttribute = null,
        ?Closure $labelCallback = null,
        bool $linked = true,
        bool $clickthrough = true,
        ?Closure $renderUsing = null,
        ?bool $searchable = null,
        ?Closure $searchUsing = null,
    ): SelectColumn {
        $render = self::renderer($labelAttribute, $labelCallback, $linked, $clickthrough, $renderUsing);

        $column
            ->allowOptionsHtml()
            ->native(false)
            ->getOptionLabelFromRecordUsing($render);

        if ($searchable !== null) {
            $column->searchableOptions($searchable);
        }

        if ($models !== null) {
            $column->options(static fn (SelectColumn $column): array => self::renderModels(
                self::resolveModels($models instanceof Closure ? $column->evaluate($models) : $models),
                $render,
            ));
        }

        if ($models !== null || $searchUsing !== null) {
            $plainLabel = self::plainLabeler($labelAttribute, $labelCallback, $renderUsing, $render);

            $column->getOptionsSearchResultsUsing(static fn (SelectColumn $column, string $search): array => self::search(
                $column,
                $search,
                $models,
                $searchUsing,
                $render,
                $plainLabel,
            ));
        }

        return $column;
    }

    /**
     * The recipe for {@see MultiSelectColumn}: an inline multi-select cell.
     *
     * Everything `applyToColumn()` does, plus what a multi-select cell needs on
     * top — the labels of the selected values, rendered with the same function
     * as the options, and an allow-list that costs no markup:
     *
     * - selected labels: a relation column holds its related records, which are
     *   rendered as they are (the column eager-loads them). Keys — an array
     *   attribute, a custom `getStateUsing()` — are looked up among the given
     *   models, which runs that lookup once per row.
     * - allow-list: the keys of the given models. With `searchUsing:` the column
     *   cannot know which records a search may return, so it stays at the
     *   options — a pick found only by searching is refused until the call site
     *   passes `allowedValuesUsing()`. Failing closed is deliberate: the
     *   endpoint behind the cell checks no policy.
     * - searchable unless told otherwise: a list of pills without a search box
     *   is a list to scroll.
     *
     * @param  iterable<int, Model>|(Closure(): iterable<int, Model>)|null  $models
     * @param  (Closure(Model): string)|null  $renderUsing
     * @param  (Closure(string): iterable<int, Model>)|null  $searchUsing
     */
    public static function applyToMultiColumn(
        MultiSelectColumn $column,
        iterable|Closure|null $models = null,
        ?string $labelAttribute = null,
        ?Closure $labelCallback = null,
        bool $linked = true,
        bool $clickthrough = true,
        ?Closure $renderUsing = null,
        ?bool $searchable = null,
        ?Closure $searchUsing = null,
    ): MultiSelectColumn {
        $render = self::renderer($labelAttribute, $labelCallback, $linked, $clickthrough, $renderUsing);

        $column
            ->allowOptionsHtml()
            ->native(false)
            ->searchableOptions($searchable ?? true);

        if ($models !== null) {
            $column->options(static fn (MultiSelectColumn $column): array => self::renderModels(
                self::resolveModels($models instanceof Closure ? $column->evaluate($models) : $models),
                $render,
            ));

            if ($searchUsing === null) {
                $column->allowedValuesUsing(static fn (MultiSelectColumn $column): array => self::resolveModels(
                    $models instanceof Closure ? $column->evaluate($models) : $models,
                )->map(static fn (Model $model): mixed => $model->getKey())->values()->all());
            }
        }

        if ($models !== null || $searchUsing !== null) {
            $plainLabel = self::plainLabeler($labelAttribute, $labelCallback, $renderUsing, $render);

            $column->getOptionsSearchResultsUsing(static fn (MultiSelectColumn $column, string $search): array => self::search(
                $column,
                $search,
                $models,
                $searchUsing,
                $render,
                $plainLabel,
            ));
        }

        $column->selectedOptionLabelsUsing(static fn (MultiSelectColumn $column): array => self::renderSelected(
            $column,
            $models,
            $render,
        ));

        return $column;
    }

    /**
     * Labels of a multi-select cell's selected values — see `applyToMultiColumn()`.
     *
     * @param  iterable<int, Model>|Closure|null  $models
     * @param  Closure(Model): string  $render
     * @return array<int|string, string>
     */
    protected static function renderSelected(MultiSelectColumn $column, iterable|Closure|null $models, Closure $render): array
    {
        $state = $column->getState();
        $items = collect(is_iterable($state) ? $state : ($state === null ? [] : [$state]));
        $records = $items->filter(static fn (mixed $item): bool => $item instanceof Model);

        if ($records->count() === $items->count()) {
            return self::renderModels($records->values(), $render);
        }

        if ($models === null) {
            return [];
        }

        $keys = $items
            ->map(static fn (mixed $item): mixed => $item instanceof Model ? $item->getKey() : $item)
            ->filter(static fn (mixed $key): bool => is_scalar($key))
            ->map(strval(...))
            ->all();

        return self::renderModels(
            self::resolveModels($models instanceof Closure ? $column->evaluate($models) : $models)
                ->filter(static fn (Model $model): bool => in_array((string) $model->getKey(), $keys, true)),
            $render,
        );
    }

    /**
     * The one function that turns a record into pill markup. Every label source
     * of the select — options, selected value, search results — goes through it.
     *
     * @return Closure(Model): string
     */
    protected static function renderer(
        ?string $labelAttribute,
        ?Closure $labelCallback,
        bool $linked,
        bool $clickthrough,
        ?Closure $renderUsing,
    ): Closure {
        return $renderUsing !== null
            ? static fn (Model $record): string => (string) $renderUsing($record)
            : static fn (Model $record): string => ModelReferencePresenter::modelSelectOption(
                $record,
                $labelAttribute,
                $labelCallback,
                $linked,
                $clickthrough,
            );
    }

    /**
     * The text the user SEES for a record — what a search term is matched
     * against. Without a custom renderer that is the label itself, which is
     * cheap (no icon, no link, no authorization per record). A custom renderer
     * hides what its label is, so there the rendered pill is stripped to its
     * text.
     *
     * @return Closure(Model): string
     */
    protected static function plainLabeler(
        ?string $labelAttribute,
        ?Closure $labelCallback,
        ?Closure $renderUsing,
        Closure $render,
    ): Closure {
        return $renderUsing !== null
            ? static fn (Model $record): string => self::plainText($render($record))
            : static fn (Model $record): string => ModelReferencePresenter::selectLabel($record, $labelAttribute, $labelCallback);
    }

    /**
     * @param  Select|SelectColumn  $component
     * @param  iterable<int, Model>|Closure|null  $models
     * @param  Closure(Model): string  $render
     * @param  Closure(Model): string  $plainLabel
     * @return array<int|string, string>
     */
    protected static function search(
        object $component,
        string $search,
        iterable|Closure|null $models,
        ?Closure $searchUsing,
        Closure $render,
        Closure $plainLabel,
    ): array {
        if ($searchUsing !== null) {
            return self::renderModels(
                self::resolveModels($component->evaluate($searchUsing, [
                    'search' => $search,
                    'query' => $search,
                    'searchQuery' => $search,
                ])),
                $render,
            );
        }

        $needle = mb_strtolower(trim($search));

        $matches = self::resolveModels($models instanceof Closure ? $component->evaluate($models) : $models)
            ->filter(static fn (Model $record): bool => $needle === ''
                || str_contains(mb_strtolower($plainLabel($record)), $needle))
            // Render only what survives and fits — the link behind every pill
            // costs an authorization check, so the whole set never gets that.
            ->take($component->getOptionsLimit());

        return self::renderModels($matches, $render);
    }

    /**
     * @param  Collection<int, Model>  $models
     * @param  Closure(Model): string  $render
     * @return array<int|string, string>
     */
    protected static function renderModels(Collection $models, Closure $render): array
    {
        return $models->mapWithKeys(static fn (Model $model): array => [$model->getKey() => $render($model)])->all();
    }

    /**
     * Visible text of a pill: tags gone, entities decoded.
     */
    protected static function plainText(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Normalise whatever the caller (or their closure) handed over. `mixed` on
     * purpose: the value may come straight out of `evaluate()`.
     *
     * @return Collection<int, Model>
     */
    protected static function resolveModels(mixed $models): Collection
    {
        if ($models instanceof Collection) {
            return $models;
        }

        if (is_array($models)) {
            return new Collection($models);
        }

        if (! is_iterable($models)) {
            throw new InvalidArgumentException('pillOptions() expects a collection, an array or a closure returning one.');
        }

        return new Collection(iterator_to_array($models));
    }
}
