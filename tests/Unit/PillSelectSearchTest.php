<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Support\Collection;
use Mmoollllee\FilamentModelLink\FilamentModelLink;
use Mmoollllee\FilamentModelLink\Forms\PillSelect;
use Mmoollllee\FilamentModelLink\Pill;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\PillModel;

beforeEach(function (): void {
    // A link on every pill — its URL is exactly the kind of markup text a naive
    // search would trip over.
    FilamentModelLink::configure()->registerCustomUrlResolver(fn () => '/resolved');
});

function searchModels(): Collection
{
    return collect([
        PillModel::fake(['name' => 'Alpha'], id: 1),
        PillModel::fake(['name' => 'Beta'], id: 2),
        PillModel::fake(['name' => 'Gamma'], id: 3),
    ]);
}

// ── Search runs on the visible text ─────────────────────────────────────

it('searches the label the user sees, not the pill markup', function (): void {
    $select = Select::make('author_id')->pillOptions(searchModels());

    // `select.js` would filter the options array by their HTML. These are the
    // words that markup is made of — none of them is a label.
    foreach (['href', 'class', 'fi-badge', 'resolved', 'span', 'clickthrough'] as $markup) {
        expect($select->getSearchResults($markup))->toBe([]);
    }

    expect(array_keys($select->getSearchResults('lph')))->toBe([1])
        ->and(array_keys($select->getSearchResults('et')))->toBe([2]);
});

it('ignores case and surrounding whitespace in the search term', function (): void {
    $select = Select::make('author_id')->pillOptions(searchModels());

    expect(array_keys($select->getSearchResults('  GAMMA ')))->toBe([3]);
});

it('returns every option for an empty term', function (): void {
    expect(Select::make('author_id')->pillOptions(searchModels())->getSearchResults(''))->toHaveCount(3);
});

it('returns the same pill markup as the options', function (): void {
    $select = Select::make('author_id')->pillOptions(searchModels());

    // Search results become options in `select.js` and may be selected, so they
    // must be the pill the options array and the chip show.
    expect($select->getSearchResults('alpha')[1])->toBe($select->getOptions()[1]);
});

it('searches the text a label callback produces', function (): void {
    $select = Select::make('author_id')->pillOptions(
        searchModels(),
        labelCallback: fn (PillModel $m): string => "{$m->name} (Team Red)",
    );

    expect(array_keys($select->getSearchResults('team red')))->toBe([1, 2, 3])
        ->and($select->getSearchResults('gamma (team'))->toHaveCount(1);
});

it('searches the text a custom renderer shows', function (): void {
    $select = Select::make('author_id')->pillOptions(
        searchModels(),
        renderUsing: fn (PillModel $m): string => Pill::for($m)->label("{$m->name} & Co")->toHtml(),
    );

    // The renderer hides what its label is — the rendered pill is stripped to
    // its text, with the entity decoded (`&amp;` is a plain `&` to the user).
    expect(array_keys($select->getSearchResults('beta & co')))->toBe([2])
        ->and($select->getSearchResults('span'))->toBe([]);
});

it('caps the results at the options limit', function (): void {
    $select = Select::make('author_id')->pillOptions(searchModels())->optionsLimit(2);

    expect($select->getSearchResults('a'))->toHaveCount(2);
});

it('re-evaluates a models closure for the search', function (): void {
    $calls = 0;

    $select = Select::make('author_id')->pillOptions(function () use (&$calls): Collection {
        $calls++;

        return searchModels();
    });

    $select->getSearchResults('beta');

    expect($calls)->toBe(1);
});

it('hands the search to a lookup closure for options too many to hold in memory', function (): void {
    $seen = [];

    $select = Select::make('author_id')->pillOptions(
        searchUsing: function (string $search, Select $component) use (&$seen): Collection {
            $seen = [$search, $component->getName()];

            return searchModels()->filter(fn (PillModel $m): bool => $m->getKey() === 2);
        },
    );

    $results = $select->getSearchResults('anything');

    // The closure gets Filament's injections, and what it returns is rendered
    // as pills — no second filter on the label.
    expect($seen)->toBe(['anything', 'author_id'])
        ->and(array_keys($results))->toBe([2])
        ->and($results[2])->toContain('Beta')->toContain('href="/resolved"');
});

it('prefers the lookup closure over filtering the given models', function (): void {
    $select = Select::make('author_id')->pillOptions(
        searchModels(),
        searchUsing: fn (): Collection => collect([PillModel::fake(['name' => 'Elsewhere'], id: 9)]),
    );

    expect(array_keys($select->getSearchResults('alpha')))->toBe([9])
        // The options themselves still come from the models.
        ->and(array_keys($select->getOptions()))->toBe([1, 2, 3]);
});

it('leaves a relationship select to Filament\'s own search', function (): void {
    // No models: the relationship builds its options and searches its own
    // columns on the server — the macro must not shadow that.
    $select = Select::make('authors')->pillOptions();

    expect($select->hasDynamicSearchResults())->toBeFalse();
});

// ── Searchable ──────────────────────────────────────────────────────────

it('leaves the searchable default alone unless asked', function (): void {
    expect(Select::make('a')->pillOptions(searchModels())->isSearchable())->toBeFalse()
        // Filament makes every multiple select searchable.
        ->and(Select::make('a')->pillOptions(searchModels())->multiple()->isSearchable())->toBeTrue();
});

it('can force the select searchable or not', function (): void {
    expect(Select::make('a')->pillOptions(searchModels(), searchable: true)->isSearchable())->toBeTrue()
        ->and(Select::make('a')->pillOptions(searchModels(), searchable: false)->multiple()->isSearchable())->toBeFalse();
});

// ── Keyboard ────────────────────────────────────────────────────────────

it('attaches the keyboard handler to a multiple select only', function (): void {
    $multiple = Select::make('a')->pillOptions(searchModels())->multiple();
    $single = Select::make('a')->pillOptions(searchModels());

    expect($multiple->getExtraAlpineAttributes())->toHaveKey('x-on:keydown')
        ->and($multiple->getExtraAlpineAttributes()['x-on:keydown'])->toContain('Backspace')->toContain('.fi-badge-delete-btn')
        ->and($single->getExtraAlpineAttributes())->not->toHaveKey('x-on:keydown');
});

it('keeps the keyboard handler safe to render unescaped', function (): void {
    $handler = Select::make('a')->pillOptions(searchModels())->multiple()->getExtraAlpineAttributes()['x-on:keydown'];

    // Filament renders extra Alpine attributes without escaping. A double quote
    // ends the attribute; `<` and `&` are not worth the parser's guesswork.
    expect($handler)
        ->not->toContain('"')
        ->not->toContain('<')
        ->not->toContain('&')
        // Alpine only wraps a leading `if (` / `const` in a function body, which
        // is what makes `return` legal in it.
        ->toStartWith('if (');
});

it('keeps the extra Alpine attributes the caller adds', function (): void {
    $select = Select::make('a')
        ->pillOptions(searchModels())
        ->multiple()
        ->extraAlpineAttributes(['x-on:custom' => 'foo()'], merge: true);

    expect($select->getExtraAlpineAttributes())->toHaveKeys(['x-on:keydown', 'x-on:custom']);
});

// ── SelectColumn ────────────────────────────────────────────────────────

it('turns a SelectColumn into a pill column', function (): void {
    $model = PillModel::fake(['name' => 'Alpha'], id: 1);

    $column = SelectColumn::make('author_id')->pillOptions(collect([$model]));

    expect($column->isOptionsHtmlAllowed())->toBeTrue()
        ->and($column->isNative())->toBeFalse()
        ->and($column->getOptions()[1])
        ->toContain('Alpha')
        ->toContain('href="/resolved"')
        ->toContain('fi-pill-clickthrough')
        // One markup for the list and the selected value.
        ->toBe($column->getOptionLabelFromRecord($model));
});

it('searches a SelectColumn on the visible text too', function (): void {
    $column = SelectColumn::make('author_id')->pillOptions(searchModels(), searchable: true);

    expect($column->areOptionsSearchable())->toBeTrue()
        ->and($column->hasDynamicOptionsSearchResults())->toBeTrue()
        ->and($column->getOptionsSearchResults('href'))->toBe([])
        ->and(array_keys($column->getOptionsSearchResults('amm')))->toBe([3]);
});

it('takes a lookup closure on a SelectColumn', function (): void {
    $column = SelectColumn::make('author_id')->pillOptions(
        searchUsing: fn (string $search): Collection => collect([PillModel::fake(['name' => "Found {$search}"], id: 7)]),
    );

    expect($column->getOptionsSearchResults('x')[7])->toContain('Found x');
});

it('gives a SelectColumn the frameless inline-edit look on request', function (): void {
    $classOf = fn (SelectColumn $column): string => (string) ($column->getExtraAttributes()['class'] ?? '');

    // Filament's own column keeps Filament's look unless asked.
    expect($classOf(SelectColumn::make('status')))->not->toContain('fi-pill-inline-edit')
        ->and($classOf(SelectColumn::make('status')->borderless()))->toContain('fi-pill-inline-edit')
        ->and($classOf(SelectColumn::make('status')->borderless(false)))->not->toContain('fi-pill-inline-edit')
        ->and($classOf(SelectColumn::make('status')->borderless(fn (): bool => true)))->toContain('fi-pill-inline-edit')
        // A class the caller set survives.
        ->and($classOf(SelectColumn::make('status')->extraAttributes(['class' => 'mine'])->borderless()))
        ->toContain('mine')
        ->toContain('fi-pill-inline-edit');
});

it('refuses to be called on anything but a select', function (): void {
    PillSelect::select(new stdClass);
})->throws(InvalidArgumentException::class);

it('refuses the column wiring on anything but a select column', function (): void {
    PillSelect::column(Select::make('a'));
})->throws(InvalidArgumentException::class);
