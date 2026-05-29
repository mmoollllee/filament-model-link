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
| `ModelLinkColumn` | `TextColumn` replacement for related-model cells. Single-relation, multi-relation, search/sort, related-tooltip. |
| `ModelLink` | Filament form component that renders the same pill chain in a `Placeholder` — great for modal detail views. |
| `Pill` | Fluent builder for one-off pills (`Pill::for($m)->icon(...)->linked()->toHtml()`). Works in Blade via `Htmlable`. |
| `ModelReferencePresenter` | Low-level static API: `renderStandalonePill`, `renderPillChain`, `textChain`, `modelSelectOptions`, `enumSelectOptions`, `urlForRelated`, … |
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

### B) Multi-relation column — many pills per cell, ancestor-deduplicated

```php
ModelLinkColumn::make('links')
    ->label('Linked to')
    ->relationships(['authors', 'posts', 'comments']);
```

Search/sort still work — the column's state is rebuilt as a comma-joined label
list of the resolved related models.

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
    'pill_chain_max_depth' => 5,    // HasPillParent walk cap
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
