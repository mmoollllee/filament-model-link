<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Tables\Columns;

use Closure;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Js;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Mmoollllee\FilamentModelLink\Forms\PillSelect;

/**
 * Inline multi-select table column — the missing sibling of `SelectColumn`.
 *
 * Filament's own select column is single-value: it never passes `isMultiple`
 * to the Alpine component it renders. That component (`selectFormComponent`,
 * shipped by filament/forms and used by every form select) does support
 * multiple values, so this column renders it directly and persists changes
 * through the same `updateTableColumnState()` endpoint the built-in editable
 * columns use.
 *
 * Options are lazy by design: a row only renders the labels of its *selected*
 * values (cheap — they come from the eager-loaded relation), and the full
 * option list is fetched via `getOptionsForJs()` when the dropdown opens.
 * Never call `getOptions()` while rendering a cell — that would run the options
 * query once per row.
 *
 * With pills, `pillOptions()` wires everything. A column named after a
 * `BelongsToMany` relation of the record needs nothing else: the state is the
 * related keys, the relation is eager-loaded, and a write `sync()`s it.
 *
 *     MultiSelectColumn::make('assignees')
 *         ->pillOptions(fn () => User::assignable()->get())
 *         ->disabled(fn (Task $record): bool => ! auth()->user()->can('update', $record));
 *
 * Wired by hand (no pills, or state that is not a relation), a call site sets
 * `getStateUsing()` (selected values), `selectedOptionLabelsUsing()` (their
 * labels), `updateStateUsing()` (the write), `allowedValuesUsing()` (a cheap
 * allow-list for validation, so saving does not rebuild the whole option
 * catalogue) and, in every case, `disabled()` — inline editable columns bypass
 * model policies, so the policy check belongs there.
 *
 * Beware: `callTableColumnMethod()` (the endpoint behind the lazy option
 * fetch) checks neither `disabled()` nor any policy, so `options()` must scope
 * its own query.
 */
class MultiSelectColumn extends SelectColumn
{
    /**
     * Hook class of an inline-edit cell that shows its input frame only on
     * hover or focus — see the package stylesheet.
     */
    public const BORDERLESS_CLASS = 'fi-pill-inline-edit';

    protected bool|Closure $isBorderless = true;

    protected ?Closure $getSelectedOptionLabelsUsing = null;

    protected ?Closure $getAllowedValuesUsing = null;

    /**
     * What the record held when the current write started — see `validate()`.
     *
     * @var array<int, string>
     */
    protected array $storedStateDuringValidation = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The rendered component is the form select, so its strings come from
        // there as well. `SelectColumn` reads its own `filament-tables::table
        // .columns.select.*` keys, which several locales — German included —
        // don't translate: the dropdown would answer in English inside an
        // otherwise translated table.
        $this->placeholder(__('filament-forms::components.select.placeholder'))
            ->noOptionsMessage(__('filament-forms::components.select.no_options_message'))
            ->noOptionsSearchResultsMessage(__('filament-forms::components.select.no_search_results_message'))
            ->optionsSearchPrompt(__('filament-forms::components.select.search_prompt'))
            ->optionsSearchingMessage(__('filament-forms::components.select.searching_message'));
    }

    /**
     * Render the options, the selected values and the search results as pills
     * — and, for a column named after a `BelongsToMany` relation, everything
     * else too. See {@see PillSelect::applyToMultiColumn()}.
     *
     * @param  iterable<int, Model>|(Closure(): iterable<int, Model>)|null  $models
     * @param  (Closure(Model): string)|null  $renderUsing
     * @param  (Closure(string): iterable<int, Model>)|null  $searchUsing
     */
    public function pillOptions(
        iterable|Closure|null $models = null,
        ?string $labelAttribute = null,
        ?Closure $labelCallback = null,
        bool $linked = true,
        bool $clickthrough = true,
        ?Closure $renderUsing = null,
        ?bool $searchable = null,
        ?Closure $searchUsing = null,
    ): static {
        PillSelect::applyToMultiColumn(
            $this,
            $models,
            $labelAttribute,
            $labelCallback,
            $linked,
            $clickthrough,
            $renderUsing,
            $searchable,
            $searchUsing,
        );

        return $this;
    }

    /**
     * Show the input frame only while the pointer is on the cell or the cell
     * has focus — a table of inline editors reads as a table, not as a form.
     * On by default; touch screens, which cannot hover, always get the frame.
     */
    public function borderless(bool|Closure $condition = true): static
    {
        $this->isBorderless = $condition;

        return $this;
    }

    public function isBorderless(): bool
    {
        return (bool) $this->evaluate($this->isBorderless);
    }

    /**
     * Labels of the currently selected values as `[value => label]`, resolved
     * per row. Keep this cheap: it runs for every rendered cell.
     */
    public function selectedOptionLabelsUsing(?Closure $callback): static
    {
        $this->getSelectedOptionLabelsUsing = $callback;

        return $this;
    }

    /**
     * Values this column accepts, as a flat list. Optional: without it the
     * allow-list falls back to the option keys, which means building every
     * option (including its label markup) on each save.
     */
    public function allowedValuesUsing(?Closure $callback): static
    {
        $this->getAllowedValuesUsing = $callback;

        return $this;
    }

    /**
     * @return array<array-key, string>
     */
    public function getSelectedOptionLabels(): array
    {
        return $this->evaluate($this->getSelectedOptionLabelsUsing) ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedValues(): array
    {
        $allowed = $this->evaluate($this->getAllowedValuesUsing)
            // `getEnabledOptions()` — not `getOptions()` — flattens option
            // groups to their values and drops `disableOptionWhen()` entries.
            ?? array_keys($this->getEnabledOptions());

        return array_map(strval(...), $allowed);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function getSelectedOptionLabelsForJs(): array
    {
        return $this->transformOptionsForJs($this->getSelectedOptionLabels());
    }

    /**
     * Single-value label resolution has no meaning here, and the inherited
     * implementation throws on an array state. Overriding it also drops the
     * inherited `#[ExposedLivewireMethod]` — PHP does not inherit attributes
     * onto an overriding declaration — so the endpoint stops dispatching.
     */
    public function getOptionLabel(bool $withDefault = true): ?string
    {
        return null;
    }

    /**
     * The parent rules resolve a single option label for the incoming state
     * and reject anything without one — an array never has one, so every save
     * would fail. Validate the array against this column's own allow-list
     * instead: the browser may only submit values this column offers.
     *
     * Values the record already holds pass too. An assignment that has since
     * left the allow-list — a user who lost the assignable role — is still in
     * every array the browser sends for that row, and refusing it would make
     * the whole cell uneditable. Re-sending it changes nothing.
     *
     * @return array<int, string|Closure>
     */
    public function getRules(): array
    {
        return [
            ...$this->getBaseRules(),
            'array',
            function (string $attribute, mixed $value, Closure $fail): void {
                $allowed = [...$this->getAllowedValues(), ...$this->storedStateDuringValidation];

                foreach (is_array($value) ? $value : [] as $item) {
                    // `is_scalar` first: the write closure receives the raw
                    // input, and casting a nested array would raise a warning
                    // that answers a crafted request with a 500.
                    if (! is_scalar($item) || ! in_array(strval($item), $allowed, true)) {
                        $fail(__('validation.in', ['attribute' => $this->getValidationAttribute()]));

                        return;
                    }
                }
            },
        ];
    }

    /**
     * Filament validates with `getStateUsing()` swapped for the INPUT, so
     * `getState()` inside a rule answers with what the browser sent, not with
     * what the record holds. Read the stored state before that swap — straight
     * from the source, past the per-record state cache.
     */
    public function validate(mixed $input): void
    {
        $callback = $this->getGetStateUsingCallback();

        $this->storedStateDuringValidation = $this->normalizeState(
            $callback !== null ? $this->evaluate($callback) : $this->getStateFromRecord(),
        );

        try {
            parent::validate($input);
        } finally {
            $this->storedStateDuringValidation = [];
        }
    }

    /**
     * Without an `updateStateUsing()`, a column named after a `BelongsToMany`
     * relation `sync()`s it; anything else falls through to Filament's
     * attribute write (a JSON array column, say).
     *
     * The write path returns `null` for every refusal (hidden, disabled,
     * unknown record), and an `updateStateUsing()` closure may legitimately
     * return `null` too — the browser could not tell the two apart. Always
     * answer with the stored state so `null` means "not saved".
     */
    public function updateState(mixed $state): mixed
    {
        $state = $this->normalizeState($state);

        $record = $this->getRecord();
        $relationship = ($this->updateStateUsing === null && $record instanceof Model)
            ? $this->getBelongsToManyRelationship($record)
            : null;

        if ($relationship === null) {
            parent::updateState($state);

            return $state;
        }

        $this->callBeforeStateUpdated($state);

        try {
            $relationship->sync($state);
        } finally {
            $this->callAfterStateUpdated($state);
        }

        return $state;
    }

    /**
     * A row renders the labels of its selected records, so a column named after
     * a relation needs that relation loaded with the page — once, not per row.
     *
     * @param  EloquentBuilder<Model>|Relation<Model, Model, mixed>  $query
     * @return EloquentBuilder<Model>|Relation<Model, Model, mixed>
     */
    public function applyEagerLoading(EloquentBuilder|Relation $query): EloquentBuilder|Relation
    {
        $query = parent::applyEagerLoading($query);

        $name = $this->getName();

        if (
            ($this->getBelongsToManyRelationship($query->getModel()) !== null)
            && (! array_key_exists($name, $query->getEagerLoads()))
        ) {
            $query->with([$name]);
        }

        return $query;
    }

    /**
     * The relation this column stands for, if its name is a `BelongsToMany`
     * relation of the record.
     *
     * Methods of the base `Model` are excluded before anything is called — the
     * same guard Eloquent applies to relation properties — so a column that
     * happens to share its name with `delete` or `push` reads a property and
     * never runs that method.
     *
     * @return BelongsToMany<Model, Model>|null
     */
    public function getBelongsToManyRelationship(Model $record): ?BelongsToMany
    {
        $name = $this->getName();

        if (
            str_contains($name, '.')
            || method_exists(Model::class, $name)
            || (! $record->isRelation($name))
        ) {
            return null;
        }

        $relationship = $record->{$name}();

        return $relationship instanceof BelongsToMany ? $relationship : null;
    }

    /**
     * Shown in the cell's error tooltip when the server refused the write.
     */
    protected function getNotSavedMessage(): string
    {
        return __('filament-model-link::columns.multi_select.not_saved');
    }

    /**
     * A record (a relation's state is its related models) counts by its key.
     * Other non-scalar entries are dropped rather than cast: `strval([])`
     * raises a warning that Laravel turns into an exception, which would answer
     * a crafted request with a 500 instead of a validation error.
     *
     * @return array<int, string>
     */
    protected function normalizeState(mixed $state): array
    {
        if ($state === null) {
            return [];
        }

        return collect(is_iterable($state) ? $state : [$state])
            ->map(fn (mixed $value): mixed => $value instanceof Model ? $value->getKey() : $value)
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(strval(...))
            ->values()
            ->all();
    }

    public function toEmbeddedHtml(): string
    {
        $ariaLabel = trim(strip_tags((string) $this->getLabel()));
        $isDisabled = $this->isDisabled();
        $name = $this->getName();
        $recordKey = $this->getRecordKey();
        $state = $this->normalizeState($this->getState());
        $livewire = $this->getTable()->getLivewire();

        $attributes = $this->getExtraAttributeBag()
            ->merge([
                // Saving lives in the outer scope so the inner component stays
                // Filament's untouched select. `serverState` mirrors what the
                // server last confirmed: it makes the write idempotent (the
                // select emits its state on init too) and is re-read from the
                // hidden input below after every Livewire message, so an edit
                // made elsewhere is adopted instead of overwritten.
                'x-data' => '{
                    child: null,
                    error: undefined,
                    isLoading: false,
                    serverState: '.Js::from($state).',
                    unsubscribeLivewireHook: null,
                    init() {
                        this.unsubscribeLivewireHook = Livewire.interceptMessage(({ message, onSuccess }) => {
                            onSuccess(() => {
                                this.$nextTick(() => {
                                    if (this.isLoading || message.component.id !== this.$wire.__instance?.id) {
                                        return
                                    }

                                    const server = this.readServerState()

                                    if (server === undefined || JSON.stringify(server) === JSON.stringify(this.serverState)) {
                                        return
                                    }

                                    this.serverState = server

                                    if (this.child) {
                                        this.child.state = server
                                    }
                                })
                            })
                        })
                    },
                    destroy() {
                        this.unsubscribeLivewireHook?.()
                    },
                    readServerState() {
                        try {
                            return JSON.parse(this.$refs.serverState?.value ?? \'\')
                        } catch (exception) {
                            return undefined
                        }
                    },
                    register(child) {
                        this.child = child
                    },
                    async saveState(state) {
                        const next = Array.isArray(state) ? state.map(String) : []

                        if (JSON.stringify(next) === JSON.stringify(this.serverState)) {
                            return
                        }

                        this.isLoading = true

                        try {
                            const response = await $wire.updateTableColumnState('.Js::from($name).', '.Js::from($recordKey).', next)

                            this.error = response?.error ?? (response === null ? '.Js::from($this->getNotSavedMessage()).' : undefined)

                            if (this.error === undefined) {
                                this.serverState = next
                            }
                        } finally {
                            this.isLoading = false
                        }
                    },
                }',
            ], escape: false)
            ->class([
                'fi-ta-select',
                'fi-inline' => $this->isInline(),
                self::BORDERLESS_CLASS => $this->isBorderless(),
            ]);

        ob_start(); ?>

        <div
            wire:ignore.self
            <?= $attributes->toHtml() ?>
        >
            <input type="hidden" value="<?= e((string) json_encode($state)) ?>" x-ref="serverState" />

            <div
                x-bind:class="{
                    'fi-disabled': isLoading || <?= Js::from($isDisabled) ?>,
                    'fi-invalid': error !== undefined,
                }"
                x-tooltip="
                    error === undefined
                        ? false
                        : {
                            content: error,
                            theme: $store.theme,
                        }
                "
                <?php /* `.stop` keeps the row out of it; no `.prevent` — that would cancel the pill links inside the selected values. */ ?>
                x-on:click.stop
                wire:ignore
                class="fi-input-wrp"
            >
                <div
                    x-load
                    x-load-src="<?= e(FilamentAsset::getAlpineComponentSrc('select', 'filament/forms')) ?>"
                    x-data="selectFormComponent({
                        canOptionLabelsWrap: <?= Js::from($this->canOptionLabelsWrap()) ?>,
                        getOptionLabelsUsing: async () => {
                            return await $wire.callTableColumnMethod(
                                <?= Js::from($name) ?>,
                                <?= Js::from($recordKey) ?>,
                                'getSelectedOptionLabelsForJs',
                            )
                        },
                        getOptionsUsing: async () => {
                            return await $wire.callTableColumnMethod(
                                <?= Js::from($name) ?>,
                                <?= Js::from($recordKey) ?>,
                                'getOptionsForJs',
                            )
                        },
                        getSearchResultsUsing: async (search) => {
                            return await $wire.callTableColumnMethod(
                                <?= Js::from($name) ?>,
                                <?= Js::from($recordKey) ?>,
                                'getOptionsSearchResultsForJs',
                                { search },
                            )
                        },
                        hasDynamicOptions: true,
                        hasDynamicSearchResults: <?= Js::from($this->hasDynamicOptionsSearchResults()) ?>,
                        initialOptionLabels: <?= Js::from($this->getSelectedOptionLabelsForJs()) ?>,
                        initialState: <?= Js::from($state) ?>,
                        isDisabled: <?= Js::from($isDisabled) ?>,
                        isHtmlAllowed: <?= Js::from($this->isOptionsHtmlAllowed()) ?>,
                        isMultiple: true,
                        isSearchable: <?= Js::from($this->areOptionsSearchable()) ?>,
                        livewireId: <?= Js::from($livewire instanceof Component ? $livewire->getId() : null) ?>,
                        loadingMessage: <?= Js::from($this->getOptionsLoadingMessage()) ?>,
                        noOptionsMessage: <?= Js::from($this->getNoOptionsMessage()) ?>,
                        noSearchResultsMessage: <?= Js::from($this->getNoOptionsSearchResultsMessage()) ?>,
                        options: [],
                        optionsLimit: <?= Js::from($this->getOptionsLimit()) ?>,
                        placeholder: <?= Js::from($this->getPlaceholder()) ?>,
                        position: <?= Js::from($this->getPosition()) ?>,
                        searchableOptionFields: <?= Js::from($this->getSearchableOptionFields()) ?>,
                        searchDebounce: <?= Js::from($this->getOptionsSearchDebounce()) ?>,
                        searchingMessage: <?= Js::from($this->getOptionsSearchingMessage()) ?>,
                        searchPrompt: <?= Js::from($this->getOptionsSearchPrompt()) ?>,
                        state: <?= Js::from($state) ?>,
                    })"
                    x-init="
                        register($data)
                        $watch('state', (value) => saveState(value))
                        $el.querySelector('.fi-select-input-btn')?.setAttribute('aria-label', <?= Js::from($ariaLabel) ?>)
                    "
                    x-on:keydown.esc="select.dropdown.isActive && $event.stopPropagation()"
                    x-on:keydown="<?= e(PillSelect::keyboardHandler()) ?>"
                    wire:key="<?= e((string) $recordKey) ?>.<?= e($name) ?>.fi-multi-select"
                    class="fi-select-input"
                >
                    <div x-ref="select"></div>
                </div>
            </div>
        </div>

        <?php return (string) ob_get_clean();
    }
}
