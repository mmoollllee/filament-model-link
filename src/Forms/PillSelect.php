<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Forms;

use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;

/**
 * Wires a `Select` to render its models as pills — the whole recipe in one
 * call, because getting it right by hand means knowing three unrelated things:
 *
 * 1. `allowHtml()` and `native(false)`, or the markup is escaped / unusable.
 * 2. BOTH label sources have to render the same pill. `select.js` fills its
 *    label repository from the OPTIONS array and only asks the server
 *    (`getOptionLabelsUsing`, fed by `getOptionLabelFromRecordUsing`) for values
 *    the options don't carry — e.g. a selected record beyond a `limit()`.
 * 3. A selected value's link only survives with click-through; in the dropdown
 *    the stylesheet suppresses it either way, so one markup serves both.
 *
 * @see Select::pillOptions() — the macro this class backs.
 */
class PillSelect
{
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
     * @param  iterable<int, Model>|(Closure(): iterable<int, Model>)|null  $models  Options
     *                                                                               source. Pass null on a `relationship()` select — Filament then builds the
     *                                                                               options from the related records itself.
     * @param  (Closure(Model): string)|null  $renderUsing  Renders a record's pill, for
     *                                                      flavors the presenter cannot infer — a per-record icon, a wrapper of your
     *                                                      own. Replaces the label/linked/clickthrough arguments and, like them,
     *                                                      serves BOTH label sources.
     */
    public static function apply(
        Select $select,
        iterable|Closure|null $models = null,
        ?string $labelAttribute = null,
        ?Closure $labelCallback = null,
        bool $linked = true,
        bool $clickthrough = true,
        ?Closure $renderUsing = null,
    ): Select {
        $render = $renderUsing !== null
            ? static fn (Model $record): string => (string) $renderUsing($record)
            : static fn (Model $record): string => ModelReferencePresenter::modelSelectOption(
                $record,
                $labelAttribute,
                $labelCallback,
                $linked,
                $clickthrough,
            );

        $select
            ->allowHtml()
            ->native(false)
            // Covers the relationship case and, on a limited options list, every
            // selected record the options array does not contain.
            ->getOptionLabelFromRecordUsing($render);

        if ($models === null) {
            return $select;
        }

        // The models closure runs through the component's own evaluator, so it
        // can take Filament's injections — `Get $get` to read a sibling field,
        // `$record` to keep the current value in a filtered list.
        return $select->options(static fn (Select $component): array => self::resolveModels(
            $models instanceof Closure ? $component->evaluate($models) : $models,
        )->mapWithKeys(fn (Model $model): array => [$model->getKey() => $render($model)])->all());
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
