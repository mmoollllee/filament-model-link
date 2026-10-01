<?php

declare(strict_types=1);

use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Mmoollllee\FilamentModelLink\FilamentModelLink;
use Mmoollllee\FilamentModelLink\Tables\Columns\MultiSelectColumn;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\Article;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\ArticlesTable;
use Mmoollllee\FilamentModelLink\Tests\Fixtures\Tag;

beforeEach(function (): void {
    // Every pill a link — the URL is the markup a naive search trips over.
    FilamentModelLink::configure()->registerCustomUrlResolver(
        fn ($related): string => '/tags/'.$related->getKey(),
    );

    $this->alpha = Tag::create(['name' => 'Alpha']);
    $this->beta = Tag::create(['name' => 'Beta']);
    $this->gamma = Tag::create(['name' => 'Gamma']);

    $this->article = Article::create(['title' => 'First']);
    $this->article->tags()->attach($this->alpha);

    ArticlesTable::$columns = fn (): array => [
        MultiSelectColumn::make('tags')->pillOptions(fn () => Tag::query()->where('retired', false)->orderBy('name')->get()),
    ];
});

afterEach(function (): void {
    ArticlesTable::$columns = null;
});

function articleTags(Article $article): array
{
    return $article->fresh()->tags->pluck('id')->sort()->values()->all();
}

// ── Rendering ───────────────────────────────────────────────────────────

it('renders the selected pills only and fetches the options when the dropdown opens', function (): void {
    Livewire::test(ArticlesTable::class)
        ->assertSee('Alpha')
        ->assertDontSee('Gamma')
        ->call('callTableColumnMethod', 'tags', (string) $this->article->id, 'getOptionsForJs')
        ->assertReturned(fn (array $options): bool => collect($options)->pluck('value')->all() === [
            (string) $this->alpha->id,
            (string) $this->beta->id,
            (string) $this->gamma->id,
        ]);
});

it('renders the Alpine multi-select with pills that stay links', function (): void {
    $html = Livewire::test(ArticlesTable::class)->html();

    expect($html)
        ->toContain('selectFormComponent(')
        ->toContain('isMultiple: true')
        // The selected pill (JSON-encoded inside the attribute) stops its click.
        ->toContain('fi-pill-clickthrough')
        // `.stop` keeps the row out of it; `.prevent` would cancel the links.
        ->toContain('x-on:click.stop')
        ->not->toContain('x-on:click.prevent')
        // Keyboard removal, as on a form select.
        ->toContain('x-on:keydown="if (');
});

it('shows its input frame only on hover unless told otherwise', function (): void {
    expect(Livewire::test(ArticlesTable::class)->html())->toContain('fi-pill-inline-edit');

    ArticlesTable::$columns = fn (): array => [
        MultiSelectColumn::make('tags')->pillOptions(fn () => Tag::all())->borderless(false),
    ];

    expect(Livewire::test(ArticlesTable::class)->html())->not->toContain('fi-pill-inline-edit');
});

it('loads the relation with the page instead of once per row', function (): void {
    foreach (range(1, 5) as $i) {
        Article::create(['title' => "Article {$i}"])->tags()->attach($this->beta);
    }

    DB::enableQueryLog();
    Livewire::test(ArticlesTable::class);
    $pivotQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'article_tag'));

    expect($pivotQueries)->toHaveCount(1);
});

// ── Writing ─────────────────────────────────────────────────────────────

it('syncs the relation the column is named after and echoes the stored state', function (): void {
    Livewire::test(ArticlesTable::class)
        ->call('updateTableColumnState', 'tags', (string) $this->article->id, [(string) $this->beta->id, (string) $this->gamma->id])
        ->assertReturned([(string) $this->beta->id, (string) $this->gamma->id]);

    expect(articleTags($this->article))->toBe([$this->beta->id, $this->gamma->id]);
});

it('detaches everything for an empty selection', function (): void {
    Livewire::test(ArticlesTable::class)
        ->call('updateTableColumnState', 'tags', (string) $this->article->id, [])
        ->assertReturned([]);

    expect(articleTags($this->article))->toBe([]);
});

it('writes an array attribute when the name is no relation', function (): void {
    ArticlesTable::$columns = fn (): array => [
        MultiSelectColumn::make('tag_ids')->pillOptions(fn () => Tag::all()),
    ];

    Livewire::test(ArticlesTable::class)
        ->call('updateTableColumnState', 'tag_ids', (string) $this->article->id, [(string) $this->beta->id]);

    expect($this->article->fresh()->tag_ids)->toBe([(string) $this->beta->id]);
});

it('labels the keys of an array attribute with the same pills', function (): void {
    $this->article->update(['tag_ids' => [(string) $this->gamma->id]]);

    ArticlesTable::$columns = fn (): array => [
        MultiSelectColumn::make('tag_ids')->pillOptions(fn () => Tag::all()),
    ];

    Livewire::test(ArticlesTable::class)->assertSee('Gamma');
});

it('leaves the write to an updateStateUsing closure', function (): void {
    // An object, not a reference: the arrow function below captures by value.
    $received = new ArrayObject;

    ArticlesTable::$columns = fn (): array => [
        MultiSelectColumn::make('tags')
            ->pillOptions(fn () => Tag::all())
            ->updateStateUsing(function (mixed $state) use ($received): void {
                $received['state'] = $state;
            }),
    ];

    Livewire::test(ArticlesTable::class)
        ->call('updateTableColumnState', 'tags', (string) $this->article->id, [(string) $this->beta->id])
        // A closure that answers null must not read as "refused".
        ->assertReturned([(string) $this->beta->id]);

    expect($received['state'])->toBe([(string) $this->beta->id])
        ->and(articleTags($this->article))->toBe([$this->alpha->id]);
});

// ── Guards ──────────────────────────────────────────────────────────────

it('rejects values outside its own options', function (): void {
    $retired = Tag::create(['name' => 'Retired', 'retired' => true]);

    Livewire::test(ArticlesTable::class)
        ->call('updateTableColumnState', 'tags', (string) $this->article->id, [(string) $retired->id])
        ->assertReturned(fn (mixed $response): bool => is_array($response) && isset($response['error']));

    expect(articleTags($this->article))->toBe([$this->alpha->id]);
});

it('keeps a stored value that has since left the options editable', function (): void {
    // Alpha is assigned, then retired — it is no longer offered, but it is in
    // every array the browser sends for this row.
    $this->alpha->update(['retired' => true]);

    Livewire::test(ArticlesTable::class)
        ->call('updateTableColumnState', 'tags', (string) $this->article->id, [(string) $this->alpha->id, (string) $this->beta->id])
        ->assertReturned([(string) $this->alpha->id, (string) $this->beta->id]);

    expect(articleTags($this->article))->toBe([$this->alpha->id, $this->beta->id]);
});

it('does not let the submitted state vouch for itself', function (): void {
    // Filament validates with `getStateUsing()` swapped for the input; reading
    // the "stored" state there would allow anything the browser sends.
    $retired = Tag::create(['name' => 'Retired', 'retired' => true]);

    Livewire::test(ArticlesTable::class)
        ->call('updateTableColumnState', 'tags', (string) $this->article->id, [(string) $this->alpha->id, (string) $retired->id])
        ->assertReturned(fn (mixed $response): bool => is_array($response) && isset($response['error']));

    expect(articleTags($this->article))->toBe([$this->alpha->id]);
});

it('answers null for a refused write', function (): void {
    ArticlesTable::$columns = fn (): array => [
        MultiSelectColumn::make('tags')->pillOptions(fn () => Tag::all())->disabled(),
    ];

    Livewire::test(ArticlesTable::class)
        ->call('updateTableColumnState', 'tags', (string) $this->article->id, [(string) $this->beta->id])
        ->assertReturned(null);

    expect(articleTags($this->article))->toBe([$this->alpha->id]);
});

it('rejects a crafted nested value instead of crashing', function (): void {
    Livewire::test(ArticlesTable::class)
        ->call('updateTableColumnState', 'tags', (string) $this->article->id, [['nested']])
        ->assertReturned(fn (mixed $response): bool => is_array($response) && isset($response['error']));

    expect(articleTags($this->article))->toBe([$this->alpha->id]);
});

it('refuses a pick found only by searchUsing until the call site passes an allow-list', function (): void {
    ArticlesTable::$columns = fn (): array => [
        MultiSelectColumn::make('tags')->pillOptions(
            fn () => Tag::query()->whereKey($this->alpha->id)->get(),
            searchUsing: fn (string $search) => Tag::query()->where('name', 'like', "%{$search}%")->get(),
        ),
    ];

    // Fails closed: the endpoint behind the cell checks no policy.
    Livewire::test(ArticlesTable::class)
        ->call('updateTableColumnState', 'tags', (string) $this->article->id, [(string) $this->alpha->id, (string) $this->gamma->id])
        ->assertReturned(fn (mixed $response): bool => is_array($response) && isset($response['error']));

    ArticlesTable::$columns = fn (): array => [
        MultiSelectColumn::make('tags')
            ->pillOptions(
                fn () => Tag::query()->whereKey($this->alpha->id)->get(),
                searchUsing: fn (string $search) => Tag::query()->where('name', 'like', "%{$search}%")->get(),
            )
            ->allowedValuesUsing(fn (): array => Tag::query()->pluck('id')->all()),
    ];

    Livewire::test(ArticlesTable::class)
        ->call('updateTableColumnState', 'tags', (string) $this->article->id, [(string) $this->alpha->id, (string) $this->gamma->id]);

    expect(articleTags($this->article))->toBe([$this->alpha->id, $this->gamma->id]);
});

// ── Search and endpoints ────────────────────────────────────────────────

it('searches the visible text through the column endpoint', function (): void {
    $search = fn (string $term) => Livewire::test(ArticlesTable::class)
        ->call('callTableColumnMethod', 'tags', (string) $this->article->id, 'getOptionsSearchResultsForJs', ['search' => $term]);

    $search('href')->assertReturned([]);
    $search('amm')->assertReturned(fn (array $results): bool => collect($results)->pluck('value')->all() === [(string) $this->gamma->id]);
});

it('serves the selected labels — as links — for the select to re-read', function (): void {
    Livewire::test(ArticlesTable::class)
        ->call('callTableColumnMethod', 'tags', (string) $this->article->id, 'getSelectedOptionLabelsForJs')
        ->assertReturned(fn (array $labels): bool => count($labels) === 1
            && $labels[0]['value'] === (string) $this->alpha->id
            && str_contains($labels[0]['label'], 'Alpha')
            // A link that stops its click: it navigates instead of opening the list.
            && str_contains($labels[0]['label'], 'href="/tags/'.$this->alpha->id.'"')
            && str_contains($labels[0]['label'], 'x-on:click.stop'));
});

it('no longer exposes the single-value label endpoint', function (): void {
    $method = new ReflectionMethod(MultiSelectColumn::class, 'getOptionLabel');

    expect($method->getAttributes(ExposedLivewireMethod::class))->toBe([]);
});

it('is searchable unless told otherwise', function (): void {
    expect(MultiSelectColumn::make('tags')->pillOptions(fn () => Tag::all())->areOptionsSearchable())->toBeTrue()
        ->and(MultiSelectColumn::make('tags')->pillOptions(fn () => Tag::all(), searchable: false)->areOptionsSearchable())->toBeFalse();
});

it('never calls a Model method that shares the column name', function (): void {
    // `delete` is a method on every model; a relation lookup must not run it.
    expect(MultiSelectColumn::make('delete')->getBelongsToManyRelationship($this->article))->toBeNull()
        ->and(Article::query()->whereKey($this->article->id)->exists())->toBeTrue()
        ->and(MultiSelectColumn::make('title')->getBelongsToManyRelationship($this->article))->toBeNull()
        ->and(MultiSelectColumn::make('tags')->getBelongsToManyRelationship($this->article))->not->toBeNull();
});

it('speaks the app language when a write is refused', function (): void {
    app()->setLocale('de');

    // JSON-encoded into the Alpine expression, as Js::from() writes it.
    expect(Livewire::test(ArticlesTable::class)->html())->toContain((string) Js::from('Änderung wurde nicht gespeichert.'));

    app()->setLocale('en');

    expect(Livewire::test(ArticlesTable::class)->html())->toContain((string) Js::from('The change was not saved.'));
});
