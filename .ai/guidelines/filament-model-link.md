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
    ->relationships(['authors', 'posts', 'comments'])
    ->maxPills(8); // remainder collapses into a "+N" pill

// To-many references need no special mode — one pill chain per entry.
ModelLinkColumn::make('tags.name')->label('Tags');

// One list names its records differently (staff: a project's internal name).
// Labels every pill, chain ancestors included; null keeps HasPillLabel's.
ModelLinkColumn::make('links')
    ->relationships(['scene', 'project'])
    ->labelUsing(fn (Model $model): ?string => $model instanceof Project ? $model->internal_name : null);
```

### Use in forms / modals

```php
use Mmoollllee\FilamentModelLink\Forms\Components\ModelLink;

ModelLink::make('team.name')->label('Team');
ModelLink::make('author.name')->label('Author');

// To-many references render one pill chain per related model.
ModelLink::make('tags.name')->label('Tags')->maxPills(6);

// Multi-relation mode — parity with ModelLinkColumn.
ModelLink::make('links')->relationships(['authors', 'posts']);
```

Rendering any iterable of models outside a component goes through the
presenter directly:

```php
ModelReferencePresenter::renderPillChains($post->tags, maxPills: 6);
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

### Pill selects (`Select::pillOptions()`)

```php
// Everything a pill select needs: options, chip labels, allowHtml(), native(false).
Select::make('author_id')
    ->pillOptions(fn () => Author::query()->orderBy('name')->get());

// On a relationship select pass no models — Filament builds the options itself.
Select::make('authors')
    ->multiple()
    ->relationship('authors', 'name')
    ->pillOptions();

// Label controls and opt-outs.
Select::make('author_id')->pillOptions($authors, 'email');
Select::make('author_id')->pillOptions($authors, labelCallback: fn (Author $a) => "{$a->name} ({$a->company})");
Select::make('author_id')->pillOptions($authors, clickthrough: false);
Select::make('author_id')->pillOptions($authors, linked: false);

// A pill flavor the presenter cannot infer (per-record icon, project wrapper):
Select::make('author_id')->pillOptions($authors, renderUsing: fn (Author $a) => Pill::for($a)
    ->icon($a->role?->icon)->label($a->name)->linked()->clickthrough()->toHtml());

// A search the macro cannot do from memory — look the records up yourself:
Select::make('customer_ids')->multiple()->pillOptions(
    fn () => Customer::latest()->limit(50)->get(),
    searchUsing: fn (string $search) => Customer::where('name', 'like', "%{$search}%")->limit(50)->get(),
);

// Force Filament's searchable flag (omitted: multiple = searchable, single = not).
Select::make('author_id')->pillOptions($authors, searchable: true);
```

Prefer the macro over wiring a select by hand: it sets BOTH label sources from
one renderer, so the dropdown option and the selected chip cannot drift apart —
and it searches the **visible text** on the server. (Filament filters a
client-side options list by the option's HTML, so a search for `href` or `a`
would otherwise keep every pill.) On a `->multiple()` select it also adds
keyboard removal: Backspace in an empty search box removes the last pill,
Backspace / Delete on a focused pill removes that pill.

### Editable select cell (`SelectColumn::pillOptions()`)

```php
// One assignment, edited in the cell. Same parameters as Select::pillOptions().
SelectColumn::make('customer_id')
    ->pillOptions(fn () => Customer::orderBy('name')->get(), searchable: true);
```

To-one only — Filament's `SelectColumn` has no multiple mode. Several
assignments go into a `MultiSelectColumn`:

```php
// A BelongsToMany relation of the row: state, eager loading, sync() and the
// allow-list come with pillOptions(). Options load when the dropdown opens.
MultiSelectColumn::make('assignees')
    ->pillOptions(fn () => User::query()->assignable()->get())
    ->disabled(fn (Task $record): bool => ! auth()->user()->can('update', $record));
```

The cell has no input frame until hover or focus (`->borderless(false)` keeps
it); give a plain `SelectColumn` the same look with `->borderless()`.

Inline columns bypass model policies (only `disabled()` is checked), and the
lazy option endpoint checks nothing — scope the options query. With
`searchUsing:` pass `allowedValuesUsing()` too, or picks found only by searching
are refused. A non-relation column (JSON array) is written as an attribute;
anything else is wired by hand: `getStateUsing()`, `updateStateUsing()`,
`selectedOptionLabelsUsing()`, `allowedValuesUsing()`.

### Per-record look (`PillStyle`)

```php
// Color, icon and image per RECORD; null keeps the class-level look.
FilamentModelLink::configure()->resolveStyleUsing(
    fn (Model $record): ?PillStyle => $record instanceof Customer
        ? PillStyle::make(color: $record->primary_color, image: $record->favicon_url)
        : null,
);

// One-off, through the builder:
Pill::for($customer)->color('#0ea5e9')->image($customer->favicon_url);
```

Fields fall back one by one (color → `HasPills::color()`, icon → icon resolver);
an explicit builder call wins. `color` is a palette name or `#rgb` / `#rrggbb` /
`rgb(r, g, b)` — a free color carries its shades inline, contrast-checked. An
image takes relative, http(s) and `data:image/…` sources, carries
`referrerpolicy="no-referrer"`, and falls back to the type icon if it fails to
load. The closure runs once per rendered pill: eager-load what it reads, and
store the favicon when the record is saved rather than fetching it on render.

### Static API (for plain text, filters, advanced cases)

```php
use Mmoollllee\FilamentModelLink\ModelReferencePresenter;

// The macro's building blocks — for a SelectFilter, a custom search source, or
// options that are not a model collection. Keep the flags identical on both.
ModelReferencePresenter::modelSelectOptions($authors, linked: true, clickthrough: true);   // options array
ModelReferencePresenter::modelSelectOption($author, linked: true, clickthrough: true);     // getOptionLabelFromRecordUsing

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
- **Pills in a `->multiple()` Select need the package stylesheet.** Filament's
  select.js wraps every selected value in an `fi-color-primary` badge
  (`createBadgeElement`), so the pill sits inside a badge. No PHP hook avoids
  it: the badge label comes from the loaded options array, and
  `getOptionLabelsUsing()` is only consulted for values missing from it. The
  package ships CSS that flattens that wrapper — import
  `vendor/mmoollllee/filament-model-link/resources/css/filament-model-link.css`
  in the panel theme. Also set `->native(false)`; a native `<select>` cannot
  render HTML options.
- **A selected value only stays a link with click-through.** The stylesheet
  neutralises pill anchors inside a select's value container unless the pill
  carries `fi-pill-clickthrough` (`clickthrough: true`, the macro's default).
  In the dropdown the suppression is unconditional — the click has to reach
  Filament's option handler.
- **Both label sources or none.** select.js seeds its label repository from the
  OPTIONS array and only asks the server (`getOptionLabelsUsing`, fed by
  `getOptionLabelFromRecordUsing()`) for values missing from it. Render both
  from the same helper — `pillOptions()` does — or a chip changes appearance
  depending on where its label came from.
  A single select has no such wrapper — there
  `->getOptionLabelFromRecordUsing()` is the right tool on a `->relationship()`
  select, because it also labels the selected value, which is what you want.
- **The stylesheet is required, not just for selects.** Pill chains get their
  geometry (flat inner edges, rounded outer, gap between chains) from
  `.fi-pill-chain` / `.fi-pill-chains` in the package CSS, not from classes in
  the markup — a package cannot emit Tailwind utilities, because its PHP sits in
  the consumer's `vendor/`, which their Tailwind build does not scan. Without
  the import, chains render as separate fully rounded pills.
- **Cell click swallowing the pill link.** `ModelLinkColumn` already calls
  `->disabledClick()` for this reason. Don't override it by hand — give the
  column an `->action()` instead: that re-enables click-through and marks the
  pills with `x-on:click.stop`, so a pill click navigates and the rest of the
  cell opens the action. The flag also marks the pill `fi-pill-clickthrough`,
  which lifts the stylesheet's `pointer-events: none` off a pill used as a
  select's selected value — right for an editable cell, wrong for a form select
  (clicking a chip would leave the form). Inside a dropdown list item the click
  must reach Filament, and the suppression there is unconditional.
- **Cycles in `pillParent()`** are bounded by `pill_chain_max_depth` (default
  4). If your chain genuinely needs more, raise that config; don't disable
  the cap.
- **Models without `HasPills`** fall back to class basename + the configured
  `default_color`. Implement `HasPills` to get a proper label and color.
- **A pill select's search must run on the visible text.** select.js filters a
  client-side options list by `option.label.includes(query)`; with `allowHtml()`
  that label is the pill's markup, so `href` or `a` keeps every option — and a
  `->multiple()` select is searchable by default. `pillOptions()` searches on
  the server. With the static API, add a `getSearchResultsUsing()` that filters
  on the plain label.
- **A per-record look is not `HasPills::color()`.** That is static, per class.
  Use `resolveStyleUsing()` / `PillStyle`; never register a Filament palette per
  record color — the page head is rendered before the record exists and a
  Livewire response never re-renders it. A free color (`#0ea5e9`) carries its
  shades inline instead.
- **Favicons: `->image()`, stored.** `->icon('/path.png')` renders an `<img>`
  through Filament but has no failure fallback, referrer policy or URL check.
  Store the favicon (file or `data:image/…` URI) when the record is saved; do not
  fetch it while rendering.
- **`SelectColumn::pillOptions()` is to-one.** Filament's `SelectColumn` holds one
  value. A to-many cell is a `MultiSelectColumn` — guarded with `disabled()`.
- **`->tooltip()` receives the resolved string state, not the model.** Use
  `->relatedTooltip(fn (?Author $author) => $author?->…)` when the tooltip needs
  the related model.
- **Tests share global resolver state.** Call `FilamentModelLink::flush()` in
  `beforeEach()` to keep tests isolated.
