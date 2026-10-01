# Filament Model Link

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mmoollllee/filament-model-link.svg?style=flat-square)](https://packagist.org/packages/mmoollllee/filament-model-link)
[![Tests](https://img.shields.io/github/actions/workflow/status/mmoollllee/filament-model-link/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mmoollllee/filament-model-link/actions/workflows/tests.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%206-brightgreen?style=flat-square)](phpstan.neon)
[![License](https://img.shields.io/packagist/l/mmoollllee/filament-model-link.svg?style=flat-square)](LICENSE.md)

Render related Eloquent models as colored **pill chains** (icon + label, parent
breadcrumb prepended) inside Filament tables, forms, free HTML, and `<option>`
lists. URLs honor `canView` / `canEdit` on the Filament resource — a pill is
only linked when the current user is authorized.

```
[ 🏢 Team ] [ 📄 Post ] [ 💬 Comment ]
```

> The examples below use a generic `Team › Post › Comment` domain. Map them to
> whatever models your app has — the package makes no assumption about them.

## What's in the box

| Surface | Use |
|---|---|
| `ModelLinkColumn` | `TextColumn` replacement for related-model cells. Single-relation, to-many, multi-relation, `maxPills()` overflow, search/sort, related-tooltip. |
| `ModelLink` | Filament form component that renders the same pill chains in a `Placeholder` — to-one, to-many, and multi-relation (`relationships()`), with `maxPills()` overflow. |
| `Pill` | Fluent builder for one-off pills (`Pill::for($m)->icon(...)->linked()->toHtml()`), per-record colors (`->color('danger')` or a free `->color('#0ea5e9')`), favicon-style images (`->image($url)`), and model-less value pills (`Pill::make('01.08.2026')`). Works in Blade via `Htmlable`. |
| `Select::pillOptions()` | Macro that turns a Filament `Select` into a pill select: options, chip labels, `allowHtml()`, `native(false)` — one call, both label sources in sync, a search that runs on the visible text, `searchUsing:` for option sets too big for memory, and keyboard removal of pills on a `->multiple()` select. |
| `SelectColumn::pillOptions()` | The same recipe for an editable table cell that holds one assignment — pill as the value and in the searchable list. |
| `MultiSelectColumn` | Inline multi-select table column — several assignments edited right in the cell, saved on every pick. `->pillOptions()` wires it; a column named after a `BelongsToMany` relation needs nothing else. |
| `PillStyle` | Per-RECORD look — color, icon, image — returned from `resolveStyleUsing()`. A customer with its favicon and brand color, instead of every customer looking alike. |
| `ModelReferencePresenter` | Low-level static API: `renderStandalonePill`, `renderPillChain`, `renderPillChains`, `renderPill`, `textChain`, `modelSelectOption(s)`, `modelSelectLabels`, `enumSelectOptions`, `urlForRelated`, … |
| `FilamentModelLink` | Fluent configurator — wire every resolver in one chain. |
| `FilamentModelLinkPlugin` | Optional Filament plugin wrapper for panel-scoped install. |
| `HasPills` / `HasPillLabel` / `HasPillParent` | Three small contracts — only `HasPills` is required. |

## Installation

```bash
composer require mmoollllee/filament-model-link
```

Optional publish steps:

```bash
php artisan vendor:publish --tag=filament-model-link-config
php artisan vendor:publish --tag=filament-model-link-ai-guidelines
php artisan vendor:publish --tag=filament-model-link-translations
```

## Theme setup

Import the stylesheet once in your panel theme
(`resources/css/filament/<panel>/theme.css`), then rebuild assets:

```css
@import "../../../../vendor/mmoollllee/filament-model-link/resources/css/filament-model-link.css";
```

Plain CSS, no Tailwind build step required, and **not optional**: this package
lives in your `vendor/`, which your Tailwind build does not scan, so all of its
layout ships here rather than as utility classes in the markup. It does eight
things:

- lays out pill chains — flat inner edges, rounded outer, so a chain reads as
  one chip with several color bands (`.fi-pill-chain`) — and spaces multiple
  chains in a wrapping row (`.fi-pill-chains`);
- centres a pill dropped into one of Filament's text containers — select values,
  table cells, infolist entries — which otherwise sits on that container's text
  baseline and pushes a select row past Filament's standard 36px input height;
- gives every linked pill (`.fi-pill-link`) a button-like hover — the surface
  reacts, not the text. A pill reads as a button, so an underline would be the
  wrong metaphor. Uses `brightness()` and `currentColor`, so it works for every
  palette color and in dark mode without a rule per color;
- flattens the outer badge Filament wraps around a selected value, but only
  when that value actually contains a pill (`:has()`);
- withholds both the hover and the click from pills in the dropdown list, where
  Filament's option handler calls `preventDefault()` and the `<a>` provably
  cannot navigate. The row's own highlight is the affordance there;
- shows the remove control (×) of a pill chip only while the chip is hovered or
  holds keyboard focus — gated on `(hover: hover)`, so touch screens, which have
  no hover, keep it visible;
- sizes an image icon (`.fi-pill-image`) to an icon's `size-4`, so a pill with a
  favicon is as tall as one with an icon, and keeps its failed-load fallback
  hidden until it is needed;
- takes the input frame off an inline-edit table cell (`.fi-pill-inline-edit`)
  until the pointer is on it or it has focus — Filament's own frame, focus ring
  and dark mode come back then; an error ring and touch screens keep it.

Points four to six are select-specific; the rest applies everywhere — tables,
forms, free HTML. Without the stylesheet, chains render as separate fully
rounded pills without gaps, pills sit a pixel or two off-centre wherever they
land, linked pills have no hover feedback, and a chip keeps an always-visible ×.

Prefer a local copy? `php artisan vendor:publish --tag=filament-model-link-styles`.

## Quick start

### 1. Make a model pill-aware

```php
use Mmoollllee\FilamentModelLink\Contracts\HasPills;
use Mmoollllee\FilamentModelLink\Contracts\HasPillLabel;
use Mmoollllee\FilamentModelLink\Contracts\HasPillParent;
use Illuminate\Database\Eloquent\Model;

class Post extends Model implements HasPills, HasPillLabel, HasPillParent
{
    public static function label(): string { return 'Post'; }
    public static function color(): string { return 'indigo'; }

    public function pillLabel(): string
    {
        return $this->title ?: 'Post #'.$this->getKey();
    }

    public function pillParent(): ?Model
    {
        return $this->team; // appears before the Post pill in chains
    }
}
```

Only `HasPills` is required. `HasPillLabel` and `HasPillParent` are opt-ins —
add them when you need a custom label or a parent breadcrumb.

### 2. Use in Filament tables and forms

```php
use Mmoollllee\FilamentModelLink\Tables\Columns\ModelLinkColumn;
use Mmoollllee\FilamentModelLink\Forms\Components\ModelLink;

// In a Filament table — searchable & sortable on the resolved label.
ModelLinkColumn::make('post.title')->label('Post')->searchable(),

// In a Filament form / modal:
ModelLink::make('post.title')->label('Post'),

// To-many references render one pill chain per related model:
ModelLink::make('tags.name')->label('Tags')->maxPills(6),
```

That's the minimum. Without further wiring you get colored, labeled pills that
link to the resolved Filament resource — auth-gated.

### 3. Wire the resolvers (one-time, AppServiceProvider::boot)

The package itself ships **no** opinion on icons, resource resolution, or URL
building. Wire your project conventions once — substitute your own
contracts, panel ids, models, and route names for the placeholders below:

```php
use Mmoollllee\FilamentModelLink\FilamentModelLink;

FilamentModelLink::configure()
    // Where do icons come from? Adapt to your own icon convention.
    ->resolveIconUsing(fn (string $class) =>
        is_subclass_of($class, \App\Contracts\HasIcon::class)
            ? $class::iconName()
            : null,
    )
    // Per-RECORD look: a customer with its own favicon and brand color. Return
    // null to keep the class-level look (see "D3" in the cookbook).
    ->resolveStyleUsing(fn (\Illuminate\Database\Eloquent\Model $record) =>
        $record instanceof \App\Models\Customer
            ? \Mmoollllee\FilamentModelLink\PillStyle::make(
                color: $record->primary_color,
                image: $record->favicon_url,
            )
            : null,
    )
    // Extra route params (e.g. a tenant/team slug for team-aware panels).
    ->resolveResourceParametersUsing(function ($related, ?string $panel) {
        $params = ['record' => $related];
        if ($panel === 'admin' && ($related->team ?? null)) {
            $params['team'] = $related->team;
        }
        return $params;
    })
    // Nested-route models that don't follow a single resource.
    ->registerCustomUrlResolver(function ($related, $viewTypes, $sourceRecord) {
        if (! $related instanceof \App\Models\Comment) {
            return null;
        }
        return route('filament.admin.resources.posts.comments.edit', [
            'record' => $related->getRouteKey(),
            'post'   => $related->post->getRouteKey(),
            'team'   => $related->post->team->slug,
        ]);
    });
```

Prefer the `Filament::registerPlugin(...)` pattern? Use the plugin instead:

```php
$panel->plugin(
    FilamentModelLinkPlugin::make()
        ->resolveIconUsing(...)
        ->registerCustomUrlResolver(...),
);
```

### Multi-panel apps — controlling which panel a pill links to

By default the package resolves a model's resource by first scanning the
**current** panel, then every other registered panel in registration order.
That's the right behavior for most apps. But if the **same model has a resource
in more than one panel** (e.g. an `admin` panel and a tenant-scoped `app`
panel), the default can pick the "wrong" panel for a pill rendered outside the
model's own panel — producing a URL the current user isn't authorized for (a
403 link).

Use `resolveResourceUsing()` to pin the preference. The closure receives the
related model and returns a `[resourceClass, panelId]` tuple (`panelId` may be
`null` to mean "the current panel"):

```php
use Filament\Facades\Filament;

FilamentModelLink::configure()
    ->resolveResourceUsing(function (mixed $related): array {
        try {
            // Prefer a resource in the current panel …
            $current = collect(Filament::getResources())->first(
                fn ($candidate) => $related instanceof ($candidate::getModel())
            );
            if ($current) {
                return [$current, null];
            }

            // … otherwise always fall back to the tenant-scoped 'app' panel,
            // never another panel that happens to be registered first.
            $app = collect(Filament::getPanel('app')->getResources())->first(
                fn ($candidate) => $related instanceof ($candidate::getModel())
            );

            return [$app, $app ? 'app' : null];
        } catch (\Throwable) {
            return [null, null]; // Filament not booted (queue/console) → no link
        }
    });
```

The `panelId` you return is passed straight to `resolveResourceParametersUsing()`,
so it's also where you decide whether to inject a tenant/team parameter.

## Use-case cookbook

### A) Single relation — auth-aware, searchable, with tooltip

```php
use App\Models\Author;

ModelLinkColumn::make('author.name')
    ->label('Author')
    ->searchable()
    ->sortable()
    ->relatedTooltip(fn (?Author $author) => $author?->summary());
```

`relatedTooltip()` is the shorthand — it receives the *resolved related model*,
not the column state, removing the `$record->author?->…` lookup boilerplate.

### B) Multi-relation — many pills per cell, ancestor-deduplicated

```php
ModelLinkColumn::make('links')
    ->label('Linked to')
    ->relationships(['authors', 'posts', 'comments'])
    ->maxPills(8);

// Same mode on the form component:
ModelLink::make('links')
    ->label('Linked to')
    ->relationships(['authors', 'posts']);
```

Search/sort still work on the column — its state is rebuilt as a comma-joined
label list of the resolved related models. `maxPills()` collapses the
remainder into a `+N` pill whose title lists the hidden labels. Under the
hood both components call `ModelReferencePresenter::renderPillChains()`,
which also accepts any iterable of models directly:

```php
ModelReferencePresenter::renderPillChains($post->tags, maxPills: 6);
```

### B2) Editable cell — pills stay navigable

A cell `action()` normally swallows the pill links: Filament wraps a column
that has one in a button carrying `wire:click.prevent.stop`. `ModelLinkColumn`
handles that for you — adding an action re-enables click-through and marks the
pills so they stop the click themselves:

```php
ModelLinkColumn::make('links')
    ->relationships(['authors', 'posts'])
    ->action(
        Action::make('editLinks')
            ->schema([Select::make('links')->multiple()->options(…)])
            ->fillForm(fn ($record) => ['links' => …])
            ->action(fn ($record, array $data) => …),
    );
```

Clicking a pill navigates to that record, clicking anywhere else in the cell
opens the action. Through the builder the same opt-in reads
`Pill::for($m)->linked()->clickthrough()`.

The same flag covers pills used as the *selected values* of a select — an
editable select cell (see "E2"), for instance. Selected values normally get
`pointer-events: none` from the stylesheet (a live link inside the select's
button navigates away mid-edit), and `stopClickPropagation: true` opts a pill
back in by marking it `fi-pill-clickthrough`. Inside a dropdown list item the
suppression is unconditional either way, so one label can serve as a selectable
option and as a navigable chip. Keep the flag off for options of a form select,
where clicking a chip should not leave the form.

### C) Pill chain in plain Blade

```php
use Mmoollllee\FilamentModelLink\Pill;

{{ Pill::for($post)->linked() }}
{{ Pill::chain($post)->labelLimit(40) }}
```

Both are `Htmlable` — interpolating in Blade emits HTML, no `e($html)` headache.

### D) Custom icon per record (e.g. user with role icon)

```php
use Mmoollllee\FilamentModelLink\Pill;

return Pill::for($user)
    ->icon($user->role?->icon)
    ->iconTooltip($user->role?->name ?? '')
    ->linked()
    ->toHtml();
```

### D2) Value pills — dates, counters, statuses without a model

```php
use Mmoollllee\FilamentModelLink\Pill;

// A deadline pill, red when overdue — no model required:
Pill::make($date->format('d.m.Y'))
    ->color($date->isPast() ? 'danger' : 'success')
    ->icon('heroicon-o-shield-check')
    ->url($noteUrl);

// Per-record status color on a model pill:
Pill::for($invoice)->color($invoice->isOverdue() ? 'danger' : 'gray');
```

### D3) Per-record look — favicon and brand color

`HasPills::color()` and the icon resolver are per class: every customer looks
the same. For a record that should look like itself, return a `PillStyle` from
the style resolver (see "Wire the resolvers"):

```php
FilamentModelLink::configure()->resolveStyleUsing(
    fn (Model $record): ?PillStyle => $record instanceof Customer
        ? PillStyle::make(
            color: $record->primary_color,      // '#0ea5e9', '#0ae', 'rgb(14, 165, 233)' or a palette name
            image: $record->favicon_url,        // shown in the icon slot
            icon: Heroicon::OutlinedBuildingOffice, // what shows while there is no image
        )
        : null,
);
```

Every field is optional and falls back to the class-level value on its own — a
customer without a favicon keeps the type icon, one without a color keeps
`HasPills::color()`. The style applies everywhere a pill is rendered: tables,
chains (each segment asks for its own record), selects, `Pill::for()`. An
explicit `Pill::for($c)->color(...)` / `->icon(...)` / `->image(...)` still wins.

- **Free colors.** A palette name works through classes because Filament emits
  the palette into the page head. A color that exists on one record is not
  there — and one registered while a Livewire response renders would never reach
  the page — so its shades travel with the pill, as inline custom properties.
  They come from Filament's own badge pipeline: the palette is generated from
  the color and the text shade is picked by WCAG contrast, in light and dark. A
  pale brand color gets dark text, a dark one light text. Accepted forms are
  `#rgb`, `#rrggbb` and `rgb(r, g, b)`; anything else (a customer's "not set")
  falls back to the default color instead of leaking into the markup.
- **Images.** Relative and http(s) URLs and `data:image/…` URIs are rendered;
  everything else (`javascript:`, `data:text/html`, …) is dropped. The image
  carries `referrerpolicy="no-referrer"` — a favicon usually lives on somebody
  else's host, which has no business learning which admin URL embedded it — and
  `loading="lazy"`. If it fails to load, Alpine's `x-on:error` swaps in the type
  icon rendered right behind it; outside a Filament panel (no Alpine) the broken
  image simply stays, in its fixed box.
- **Where the favicon comes from is yours to decide**, and worth deciding on
  purpose: fetching it on every render makes every table view depend on a third
  party. Fetch it once (on create, or when the website changes), store the file
  or a `data:image/png;base64,…` URI, and return that. A pill renders fine when
  there is nothing to return.

Filament itself renders an icon string containing a `/` as an `<img>`, so
`->icon('/favicons/acme.png')` also shows an image — without the fallback, the
referrer policy or the URL check. Use `->image()` for a favicon.

### E) Select / SelectFilter with pill options

```php
Select::make('author_id')
    ->pillOptions(fn () => Author::query()->orderBy('name')->get());
```

`pillOptions()` is a macro on Filament's `Select` and wires the whole recipe:
`allowHtml()`, `native(false)`, the options themselves, the record-based label
source, and the search — every pill click-through, so a **selected** value stays
a link (in the dropdown the stylesheet keeps it inert either way, so the click
still picks the option).

What the user gets, on a `->multiple()` select:

- a pill is a link; clicking it navigates and never opens the dropdown;
- clicking the free area of the field opens the dropdown — a searchable list of
  the same pills, where the links are inert and a click picks the row;
- the × of a pill appears on hover or keyboard focus (and stays visible on touch);
- **Backspace in an empty search box removes the last pill**, and **Backspace /
  Delete on a focused pill removes that pill** and returns the focus to the
  field. Filament already makes the × a real button that Space and Enter
  activate, and a pill link reachable with Tab — the macro only fills the gap.

Filament lists only the options that are *not* selected yet in a `->multiple()`
select, so an assigned record leaves the list until its pill is removed.

It takes a collection or a closure — the closure runs through the component's
evaluator, so it can read a sibling field or the current record:

```php
Select::make('cam_id')
    ->pillOptions(fn (Get $get, ?Scene $record) => Cam::availableBetween($get('from'), $get('to'))
        ->when($record?->cam, fn ($cams) => $cams->push($record->cam))
    );
```

It takes the same label controls as the static API, and can opt out of either
behavior:

```php
Select::make('author_id')->pillOptions($authors, 'email');
Select::make('author_id')->pillOptions($authors, labelCallback: fn (Author $a) => "{$a->name} ({$a->company})");
Select::make('author_id')->pillOptions($authors, clickthrough: false);  // selected value stays inert
Select::make('author_id')->pillOptions($authors, linked: false);        // plain pills, no <a>
```

For a pill the presenter cannot infer — a per-record icon, a wrapper of your own
— pass `renderUsing`. It replaces the label/linked/clickthrough arguments and
serves both label sources just the same:

```php
Select::make('author_id')->pillOptions($authors, renderUsing: fn (Author $a) => Pill::for($a)
    ->icon($a->role?->icon)
    ->label("{$a->name} ({$a->company})")
    ->linked()
    ->clickthrough()
    ->toHtml());
```

On a `->relationship()` select, pass no models — Filament builds the options
from the related records, and the macro renders each of them:

```php
Select::make('authors')
    ->multiple()
    ->relationship('authors', 'name')
    ->pillOptions();
```

#### Searching

Filament filters a client-side options list with
`option.label.toLowerCase().includes(query)`. With `allowHtml()` that label is
the pill's **markup** — so a search for `a`, `href` or `fi-badge` matches every
option (`<a href=…`, `class=…`) and the list never narrows. Verified in a real
panel: with the static API a search for `href` leaves all options standing.

`pillOptions()` therefore runs the search on the server, against the text the
user sees — the label, or for a custom `renderUsing` the rendered pill stripped
to its text. Case and surrounding whitespace do not matter, and the result
count follows `optionsLimit()` (default 50). The results are the same pills the
options show. Filament debounces a server search by one second; shorten it with
`->searchDebounce(300)` if your option set is small.

For an option set too big to hold in memory, look the records up yourself — the
closure takes Filament's injections, `$search` included:

```php
Select::make('customer_ids')
    ->multiple()
    ->pillOptions(
        fn () => Customer::query()->latest()->limit(50)->get(),          // the initial list
        searchUsing: fn (string $search) => Customer::query()
            ->where('name', 'like', "%{$search}%")
            ->limit(50)
            ->get(),
    );
```

A `relationship()` select needs none of this: Filament searches the related
table on the server (by the title attribute, or `->searchable([...])` columns).
Call `pillOptions()` *after* `relationship()` if you pass `searchUsing:` —
`relationship()` registers a search of its own, and the later call wins.

`searchable:` leaves Filament's default alone when omitted — a `->multiple()`
select is searchable, a single one is not — and forces it with `true` / `false`:
`->pillOptions($authors, searchable: true)`.

### E2) Editable select cell — one assignment in a table

```php
SelectColumn::make('customer_id')
    ->label('Customer')
    ->pillOptions(fn () => Customer::orderBy('name')->get(), searchable: true);
```

The same recipe on Filament's `SelectColumn`: the pill is the cell's value and
renders in the list; the pill navigates, the free area of the cell opens the
searchable list, and a pick is saved like any `SelectColumn` change. The
parameters are those of `Select::pillOptions()`.

**To-one only.** Filament's `SelectColumn` holds one value — it has no multiple
mode, and its JS component does not pass one through. Several assignments go
into a `MultiSelectColumn` (see "E3").

> `SelectColumn` saves without consulting a model policy — restrict editing with
> `->disabled(fn (Project $record) => ! auth()->user()->can('update', $record))`.

### E3) Inline multi-select cell — `MultiSelectColumn`

```php
use Mmoollllee\FilamentModelLink\Tables\Columns\MultiSelectColumn;

MultiSelectColumn::make('assignees')            // a BelongsToMany relation of the row
    ->label('Assignees')
    ->pillOptions(fn () => User::query()->assignable()->orderBy('name')->get())
    ->disabled(fn (Task $record): bool => ! auth()->user()->can('update', $record));
```

The cell shows the assigned records as pills and is the select: a pill
navigates, its × removes it, the free area opens the searchable list of the
records not assigned yet, and every pick or removal is saved at once — no modal,
no save button. Backspace in an empty search box removes the last pill.

The cell has no input frame until the pointer is on it or it has focus, so a
table of inline editors still reads as a table. `->borderless(false)` keeps the
frame; Filament's own `SelectColumn` gets the same look with `->borderless()`
(opt-in there, since that column is Filament's):

```php
SelectColumn::make('status')->options(…)->borderless();
```

Filament's `SelectColumn` is single-value, but the Alpine component behind
every *form* select supports `isMultiple`. This column renders that component
in the cell and saves through the same `updateTableColumnState()` endpoint the
built-in editable columns use.

For a column named after a `BelongsToMany` relation, `pillOptions()` is all it
takes:

- **state** — the related keys; the relation is eager-loaded with the page, so
  50 rows cost one pivot query, not 50;
- **write** — `sync()` of the relation;
- **labels** — the selected records rendered with the same pill as the options;
- **allow-list** — a write may only contain keys of the given models, plus keys
  the row already holds (an assignee who has since left the options would
  otherwise make the cell uneditable for good);
- **lazy options** — a row renders only its selected pills; the option list is
  fetched when the dropdown opens;
- **search** — on the visible text, as for a form select.

A column that is not a relation — a JSON array attribute — is written as an
attribute; its keys are labelled by looking them up among the given models
(once per row — pass `selectedOptionLabelsUsing()` if that is too much).

Anything else is wired by hand, as on any column: `getStateUsing()`,
`updateStateUsing()`, `selectedOptionLabelsUsing()`, `allowedValuesUsing()`.
An `updateStateUsing()` closure replaces the `sync()`.

**Guard it.** Inline columns save without consulting a model policy — only
`disabled()` is checked, so the policy check belongs there. And the endpoint
behind the lazy option list (`callTableColumnMethod()`) checks neither
`disabled()` nor a policy, so `pillOptions()` must be given an already scoped
query.

With `searchUsing:` the column cannot know which records a search may return:
the allow-list stays at the given models, and a pick found only by searching is
refused until you pass `allowedValuesUsing()` — failing closed, on purpose.

A refused write (disabled, hidden, invalid) shows "The change was not saved." as
the cell's error tooltip — translated (`en`, `de`; publish with
`--tag=filament-model-link-translations`).

Enum pills have no link and no macro of their own:

```php
Select::make('status')
    ->allowHtml()
    ->options(fn () => ModelReferencePresenter::enumSelectOptions(PostStatus::class));
```

> **Why one call sets two label sources.** Filament's `select.js` fills its
> label repository from the OPTIONS array (`populateLabelRepositoryFromOptions`)
> and only asks the server — `initialOptionLabel(s)`, `getOptionLabelUsing`,
> `getOptionLabelsUsing`, i.e. what `getOptionLabelFromRecordUsing()` feeds — for
> values the options do not carry, e.g. a selected record beyond a `limit()`.
> Set only one of them and a chip silently changes appearance depending on where
> its label came from. The macro sets both from the same renderer; by hand, use
> `ModelReferencePresenter::modelSelectOption()` for the record callback and
> `modelSelectOptions()` for the array — same markup, same flags.
>
> **`->multiple()` needs the package stylesheet.** Filament's select JS renders
> every selected value through `createBadgeElement`, which hardcodes an
> `fi-color-primary` badge around the option label — so a pill lands inside a
> badge, and no PHP hook can prevent it. The fix is CSS, and the package ships
> it — see [Theme setup](#theme-setup). It flattens the outer badge only when it
> actually wraps a pill (`:has()`), so selects without pills keep Filament's
> normal styling.
>
> **The static API is still there** for cases the macro does not cover — a
> `SelectFilter` (not a `Select`), a custom search source, or options built from
> something other than a model collection:
>
> ```php
> Select::make('authors')
>     ->multiple()
>     ->allowHtml()
>     ->native(false)
>     ->options(fn () => ModelReferencePresenter::modelSelectOptions($authors, linked: true, clickthrough: true))
>     ->getSearchResultsUsing(fn (string $search) => ModelReferencePresenter::modelSelectOptions(
>         Author::search($search)->get(),
>         linked: true,
>         clickthrough: true,
>     ));
> ```
>
> Colour or icon that varies per record — a status, a kind — is not a job for
> `HasPills`, which is class-level. Build the pill explicitly there:
> `Pill::make($record->name)->color($record->kind->color())->icon($record->kind->icon())`.
>
> Need plain text somewhere (a `->native()` select, an export, a notification)?
> `modelSelectLabels()` is the pill-free counterpart and honors `HasPillLabel`.

### F) Plain-text breadcrumb (filter indicators, notifications)

```php
ModelReferencePresenter::textChain($post);
// "Team › Post › Comment"
```

### G) `recordTitle` in Associate/Dissociate dialogs

```php
->recordTitle(fn (Team $record): string =>
    ModelReferencePresenter::renderPillChain($record),
)
```

## Configuration

```php
// config/filament-model-link.php
return [
    'pill_chain_max_depth' => 4,    // HasPillParent walk cap
    'ancestor_label_limit' => 20,   // Default char-truncation for ancestor pills
    'default_view_types'   => ['view', 'edit'],
    'default_color'        => 'gray',
];
```

## Authorization

`urlForRelated()` runs `canView` / `canEdit` (or `canCreate`) on the resolved
Filament resource. **Throwables count as "not authorized"** — a broken policy
never produces a falsely built link. The URL is `null` when the user can't
access any of the requested view types.

Pass `viewTypes(['view'])` for read-only links:

```php
ModelLinkColumn::make('author.name')->viewTypes(['view']);
ModelLink::make('author.name')->viewTypes(['view']);
```

## AI agent guidelines

Agent-facing documentation lives at:

- `.ai/guidelines/filament-model-link.md` — Markdown snippet placed where
  Laravel Boost discovers third-party guidelines. Rename to `.blade.php`
  if you want the file rendered through Boost's Blade pipeline (for
  conditional rules, version interpolation, etc.).
- `AGENTS.md` — same content at the repo root, for tools that pick up
  `AGENTS.md` automatically.

Publish into the host project with:

```bash
php artisan vendor:publish --tag=filament-model-link-ai-guidelines
```

## Testing & quality

The package ships with a unit suite and a Livewire feature suite (Pest +
Orchestra Testbench, a real Filament table on in-memory SQLite), PHPStan
(Larastan) level 6, and Pint. CI runs all three across PHP 8.2 / 8.3 / 8.4
(plus a `prefer-lowest` job that validates the declared dependency floors).

```bash
composer install
composer test      # vendor/bin/pest
composer analyse   # vendor/bin/phpstan analyse
composer format    # vendor/bin/pint
```

In your **host project**, reset hooks between tests so static state doesn't
leak:

```php
use Mmoollllee\FilamentModelLink\FilamentModelLink;

beforeEach(fn () => FilamentModelLink::flush());
```

## Versioning

Follows [Semantic Versioning](https://semver.org). Breaking changes go to
major versions; everything else is additive.

## License

MIT — see [LICENSE.md](LICENSE.md).
