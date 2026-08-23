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
     */
    public static function apply(
        Select $select,
        iterable|Closure|null $models = null,
        ?string $labelAttribute = null,
        ?Closure $labelCallback = null,
        bool $linked = true,
        bool $clickthrough = true,
    ): Select {
        $select
            ->allowHtml()
            ->native(false)
            // Covers the relationship case and, on a limited options list, every
            // selected record the options array does not contain.
            ->getOptionLabelFromRecordUsing(fn (Model $record): string => ModelReferencePresenter::modelSelectOption(
                $record,
                $labelAttribute,
                $labelCallback,
                $linked,
                $clickthrough,
            ));

        if ($models === null) {
            return $select;
        }

        return $select->options(fn (): array => ModelReferencePresenter::modelSelectOptions(
            self::resolveModels($models),
            $labelAttribute,
            $labelCallback,
            $linked,
            $clickthrough,
        ));
    }

    /**
     * @param  iterable<int, Model>|(Closure(): iterable<int, Model>)  $models
     * @return Collection<int, Model>
     */
    protected static function resolveModels(iterable|Closure $models): Collection
    {
        $resolved = $models instanceof Closure ? $models() : $models;

        if ($resolved instanceof Collection) {
            return $resolved;
        }

        return new Collection(is_array($resolved) ? $resolved : iterator_to_array($resolved));
    }
}
