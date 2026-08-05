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
| `Pill` | Fluent builder for one-off pills (`Pill::for($m)->icon(...)->linked()->toHtml()`), per-record colors (`->color('danger')`), and model-less value pills (`Pill::make('01.08.2026')`). Works in Blade via `Htmlable`. |
| `ModelReferencePresenter` | Low-level static API: `renderStandalonePill`, `renderPillChain`, `renderPillChains`, `renderPill`, `textChain`, `modelSelectOptions`, `modelSelectLabels`, `enumSelectOptions`, `urlForRelated`, … |
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
```

## Theme setup

Import the stylesheet once in your panel theme
(`resources/css/filament/<panel>/theme.css`), then rebuild assets:

```css
@import "../../../../vendor/mmoollllee/filament-model-link/resources/css/filament-model-link.css";
```

Plain CSS, no Tailwind build step required, and **not optional**: this package
lives in your `vendor/`, which your Tailwind build does not scan, so all of its
layout ships here rather than as utility classes in the markup. It does five
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
  cannot navigate. The row's own highlight is the affordance there.

Points four and five are select-specific; the rest applies everywhere — tables,
forms, free HTML. Without the stylesheet, chains render as separate fully
rounded pills without gaps, pills sit a pixel or two off-centre wherever they
land, and linked pills have no hover feedback.

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
opens the action.

The same flag covers pills used as the *selected values* of a select — an
inline multi-select cell, for instance. Selected values normally get
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

### E) Select / SelectFilter with pill options

```php
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;

Select::make('author_id')
    ->allowHtml()  // required — pills are raw HTML
    ->options(fn () => ModelReferencePresenter::modelSelectOptions(
        Author::query()->orderBy('name')->get(),
        linked: true,
    ));

Select::make('status')
    ->allowHtml()
    ->options(fn () => ModelReferencePresenter::enumSelectOptions(PostStatus::class));
```

> **`->multiple()` needs the package stylesheet.** Filament's select JS renders
> every selected value through `createBadgeElement`, which hardcodes an
> `fi-color-primary` badge around the option label — so a pill lands inside a
> badge. And no PHP hook can prevent it: the badge label comes straight from the
> loaded options array (select.js fills `labelRepository` from it and only falls
> back to `getOptionLabelsUsing()` for values *missing* from it), so the dropdown
> and the badge always share the same markup.
>
> The fix is CSS, and the package ships it — see [Theme setup](#theme-setup).
> It flattens the outer badge only when it actually wraps a pill (`:has()`), so
> selects without pills keep Filament's normal styling.
>
> ```php
> Select::make('authors')
>     ->multiple()
>     ->relationship('authors', 'name')
>     ->allowHtml()      // pills are raw HTML
>     ->native(false)    // required: a native <select> cannot render them
>     ->options(fn () => ModelReferencePresenter::modelSelectOptions($authors, linked: true))
>     ->getSearchResultsUsing(fn (string $search) => ModelReferencePresenter::modelSelectOptions(
>         Author::search($search)->get(),
>         linked: true,
>     ));
> ```
>
> In a `->multiple()` select, avoid `->getOptionLabelFromRecordUsing()`. It
> feeds Filament's `getOptionLabelsUsing()`, so it labels the selected values
> too — and there each one lands inside Filament's own badge. Use `options()` /
> `getSearchResultsUsing()`, which say what they do.
>
> In a **single** select the opposite holds: there is no wrapper badge, the
> selected value is rendered into `.fi-select-input-value-label` on its own, and
> labelling it is exactly what you want. On a `->relationship()` select,
> `->getOptionLabelFromRecordUsing()` is then the right tool — it keeps
> `createOptionForm()` and the relationship's own query intact:
>
> ```php
> Select::make('author_id')
>     ->relationship('author', 'name')
>     ->getOptionLabelFromRecordUsing(fn (Author $record) => Pill::for($record)->toHtml())
>     ->allowHtml()
>     ->native(false);
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

The package ships with 27 unit tests (Pest + Orchestra Testbench), PHPStan
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
