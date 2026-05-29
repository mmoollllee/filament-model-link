## Filament Model Link

`mmoollllee/filament-model-link` renders related Eloquent models as colored
**pill chains** (icon + label, with the model's HasPillParent chain prepended)
inside Filament tables, forms, free HTML, and `<option>` lists.

### Surface area (highest abstraction first)

- `Mmoollllee\FilamentModelLink\Tables\Columns\ModelLinkColumn` — table column
- `Mmoollllee\FilamentModelLink\Forms\Components\ModelLink` — form placeholder
- `Mmoollllee\FilamentModelLink\Pill` — fluent builder, `Htmlable`
- `Mmoollllee\FilamentModelLink\ModelReferencePresenter` — static API
- `Mmoollllee\FilamentModelLink\FilamentModelLink` — fluent configurator
- `Mmoollllee\FilamentModelLink\FilamentModelLinkPlugin` — optional Filament plugin
- Contracts: `HasPills` (required), `HasPillLabel` (optional), `HasPillParent` (optional)

> Examples below use a generic `Team › Post › Comment` domain (with `Author` as
> a sibling relation). Map them to your own models — the package assumes none.

### Make a model pill-aware

```php
use Mmoollllee\FilamentModelLink\Contracts\HasPills;

class Post extends Model implements HasPills
{
    public static function label(): string { return 'Post'; }
    public static function color(): string { return 'indigo'; }
}
```

Add `HasPillLabel::pillLabel(): string` only when the visible label is not
`$name`/`$title`. Add `HasPillParent::pillParent(): ?Model` only when the model
needs a parent breadcrumb pill in front of it.

### Use in tables

```php
use Mmoollllee\FilamentModelLink\Tables\Columns\ModelLinkColumn;

// Single-relation (default). Searchable/sortable on the resolved display label.
ModelLinkColumn::make('team.name')->label('Team')->searchable();

// Tooltip on the related model — no $record fishing required.
ModelLinkColumn::make('author.name')
    ->relatedTooltip(fn (?Author $author) => $author?->summary());

// Read-only links only.
ModelLinkColumn::make('post.title')->viewTypes(['view']);

// Multi-relation: render pills for several relations at once.
// Ancestors that already appear in another pill chain are de-duplicated.
// State is rebuilt as a comma-joined label list for search/sort.
ModelLinkColumn::make('links')
    ->relationships(['authors', 'posts', 'comments']);
```

### Use in forms / modals

```php
use Mmoollllee\FilamentModelLink\Forms\Components\ModelLink;

ModelLink::make('team.name')->label('Team');
ModelLink::make('author.name')->label('Author');
```

### Use the Pill builder (one-off custom pills)

```php
use Mmoollllee\FilamentModelLink\Pill;

// Plain single pill, linked when the user is authorized.
Pill::for($post)->linked();

// Parent-aware chain.
Pill::chain($post)->labelLimit(40)->toHtml();

// Caller-provided icon overrides the default (e.g. user with role icon).
Pill::for($user)
    ->icon($user->role?->icon)
    ->iconTooltip($user->role?->name ?? '')
    ->linked()
    ->toHtml();

// Force a specific URL (beats resolver + custom URL resolvers).
Pill::for($model)->url($explicitUrl)->toHtml();
```

The Pill is `Htmlable`, so `{{ Pill::for($u) }}` works directly in Blade.

### Static API (for Select options, plain text, advanced cases)

```php
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;

// Pill-styled <option>s for a Filament Select. allowHtml() is required.
Select::make('author_id')
    ->allowHtml()
    ->options(fn () => ModelReferencePresenter::modelSelectOptions(
        Author::query()->orderBy('name')->get(),
        labelAttribute: 'name',
        linked: true,
    ));

// Pill-styled <option>s for an enum.
Select::make('status')
    ->allowHtml()
    ->options(fn () => ModelReferencePresenter::enumSelectOptions(PostStatus::class));

// Plain text breadcrumb for non-HTML contexts.
ModelReferencePresenter::textChain($post);   // "Team › Post › Comment"
ModelReferencePresenter::textChain($post, ' / ');

// recordTitle in Associate dialogs.
->recordTitle(fn (Team $r) => ModelReferencePresenter::renderPillChain($r));
```

### Project wiring (one-time, AppServiceProvider::boot)

```php
use Mmoollllee\FilamentModelLink\FilamentModelLink;

// Substitute your own contracts, panel ids, models, and route names.
FilamentModelLink::configure()
    // Icon source — wire to your project's icon convention.
    ->resolveIconUsing(function (string $class) {
        if (is_subclass_of($class, \App\Contracts\HasIcon::class)) {
            return $class::iconName();
        }
        return null;
    })
    // Multi-panel route parameters (e.g. a team/tenant slug).
    ->resolveResourceParametersUsing(function ($related, ?string $panel) {
        $params = ['record' => $related];
        if ($panel === 'admin' && method_exists($related, 'team') && $related->team) {
            $params['team'] = $related->team;
        }
        return $params;
    })
    // Nested-route models (resource path doesn't follow the standard pattern).
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

Prefer panel-scoped install? Use `FilamentModelLinkPlugin::make()->resolveIconUsing(...)` in your PanelProvider via `$panel->plugin(...)`.

### Authorization

`urlForRelated()` runs `canView` / `canEdit` (and `canCreate` for `'create'`)
on the resolved Filament resource. **Throwables count as "not authorized"** —
a broken policy never produces a falsely built link. The result is `null`
when the user can't access any of the requested view types. Pass an
explicit `viewTypes(['view'])` to force read-only links.

### Common pitfalls

- **Selects must opt into HTML.** `modelSelectOptions()` / `enumSelectOptions()`
  return raw HTML strings; pair them with `->allowHtml()` or `->native(false)`
  on the `Select`. Without it Filament will escape the pill markup.
- **Cell click swallowing the pill link.** `ModelLinkColumn` already calls
  `->disabledClick()` for this reason. Don't override it.
- **Cycles in `pillParent()`** are bounded by `pill_chain_max_depth` (default
  5). If your chain genuinely needs more, raise that config; don't disable
  the cap.
- **Models without `HasPills`** fall back to class basename + the configured
  `default_color`. Implement `HasPills` to get a proper label and color.
- **`->tooltip()` receives the resolved string state, not the model.** Use
  `->relatedTooltip(fn (?Author $author) => $author?->…)` when the tooltip needs
  the related model.
- **Tests share global resolver state.** Call `FilamentModelLink::flush()` in
  `beforeEach()` to keep tests isolated.
