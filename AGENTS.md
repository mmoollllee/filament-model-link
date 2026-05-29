# Agent guidelines — `mmoollllee/filament-model-link`

These guidelines mirror Laravel Boost's package-rules convention and are also
shipped as a Markdown snippet at `.ai/guidelines/filament-model-link.md`.
If you are an AI coding agent working in a project that includes this package,
prefer these rules over guesses based on similarly-named conventions.

## What this package does

Renders related Eloquent models as colored **pill chains** (icon + label,
parents prepended via `HasPillParent`) inside Filament tables, forms, free
HTML, and `<option>` lists.

Three abstraction levels — pick the lowest that fits the call site:

1. **Filament components** (`ModelLinkColumn`, `ModelLink`) — drop-in for
   table cells and form placeholders that reference a single related model.
2. **`Pill` builder** — fluent, `Htmlable`, for one-off pills in custom
   Livewire/Blade where you need precise control (custom icon, custom URL).
3. **`ModelReferencePresenter` static API** — for select options, plain text
   chains, multi-pill rendering, anything outside the first two.

## When to reach for which

| Scenario | Use |
|---|---|
| Table column referencing `author.name`, `team.name`, etc. | `ModelLinkColumn::make()` |
| Modal/Form placeholder referencing a related model | `ModelLink::make()` |
| Cell needs many related models pilled at once | `ModelLinkColumn::make()->relationships([...])` |
| Tooltip should show *related-model* data | `->relatedTooltip(fn ($related) => …)` |
| Pill needs a record-specific icon (e.g. user with role icon) | `Pill::for($m)->icon(...)->iconTooltip(...)->toHtml()` |
| `Select::options()` populated with model pills | `ModelReferencePresenter::modelSelectOptions(...)` + `->allowHtml()` |
| `Select::options()` populated with enum pills | `ModelReferencePresenter::enumSelectOptions(...)` + `->allowHtml()` |
| Plain text breadcrumb (filter indicators, mail, log) | `ModelReferencePresenter::textChain($m)` |
| `recordTitle` in Associate dialog | `ModelReferencePresenter::renderPillChain($record)` |

## Making a model pill-aware

```php
use Mmoollllee\FilamentModelLink\Contracts\HasPills;

class Post extends Model implements HasPills
{
    public static function label(): string { return 'Post'; }
    public static function color(): string { return 'indigo'; }
}
```

- Add `HasPillLabel::pillLabel(): string` **only if** the visible label is not
  just `$name` or `$title`.
- Add `HasPillParent::pillParent(): ?Model` **only if** the model should render
  with a parent pill prefix.

Do not add an icon contract — icons are resolved by the configured icon
resolver (see "Project wiring" below).

## Project wiring (one-time)

Defaults: no icons, resources scanned across all Filament panels, no custom
URL builders. Wire your project in `AppServiceProvider::boot()`:

```php
use Mmoollllee\FilamentModelLink\FilamentModelLink;

// Substitute your own contracts, panel ids, models, and route names.
FilamentModelLink::configure()
    ->resolveIconUsing(fn (string $class) =>
        is_subclass_of($class, \App\Contracts\HasIcon::class)
            ? $class::iconName()
            : null,
    )
    ->resolveResourceParametersUsing(function ($related, ?string $panel) {
        $params = ['record' => $related];
        if ($panel === 'admin' && ($related->team ?? null)) {
            $params['team'] = $related->team;
        }
        return $params;
    })
    ->registerCustomUrlResolver(function ($related, $viewTypes, $sourceRecord) {
        if ($related instanceof \App\Models\Comment) {
            return route('filament.admin.resources.posts.comments.edit', [
                'record' => $related->getRouteKey(),
                'post'   => $related->post->getRouteKey(),
                'team'   => $related->post->team->slug,
            ]);
        }
        return null;
    });
```

Alternative: panel-scoped via `Filament::registerPlugin(FilamentModelLinkPlugin::make()->...)`.

## Rules of thumb

- **Don't fork `ModelReferencePresenter` into the project.** Extend behavior
  via the resolver hooks; never by copying the class.
- **`HasPills::color()` returns a Filament color name.** Same vocabulary as
  `TextColumn::badge()->color()`: `'primary'`, `'success'`, `'warning'`,
  `'danger'`, `'gray'`, or any custom color registered with the panel.
- **Models don't need an icon contract.** Icons live behind the resolver —
  keep model contract surface small.
- **`HasPillParent` is visual context, not authorization.** Authorization is
  checked per pill against the resource policy.
- **`->disabledClick()` on `ModelLinkColumn` is non-negotiable** — already set
  in `setUp()`. Without it, the cell click swallows the embedded `<a>`.
- **`Select::options(modelSelectOptions(...))`** requires `->allowHtml()` (or
  `->native(false)`) on the Select. Without it the pill markup is escaped.
- **Tests should `FilamentModelLink::flush()`** in a `beforeEach()` — the
  resolver hooks are global static state.
- **Project-specific wrappers belong in the project**, not the package. The
  package stays generic; e.g. a "user with role icon" wrapper lives in your
  application (say `UserPillPresenter::render(...)`), delegating to
  `Pill::for(...)`.

## Anti-patterns to refuse

- Writing inline HTML for "icon + label + link" in a Filament resource when
  this package already covers it. Use `ModelLinkColumn` / `ModelLink` / `Pill`.
- Reading `$model->name` / `$model->title` for display when the model
  implements `HasPillLabel`. Always go through `basePillLabel()` /
  `renderPillChain()` so the override is respected.
- Building Filament resource URLs by hand (`route('filament.admin.resources.…')`)
  when `urlForRelated()` already enforces authorization. The exception is
  nested-route models — wire those via `registerCustomUrlResolver()`, not by
  sidestepping the presenter.
- Adding a custom icon contract / icon-method requirement (e.g. `iconName()`)
  to `HasPills`. Icon source is intentionally a resolver concern.
- Using `->tooltip(fn ($record) => $record->author?->something())` for a
  related-model column. Use `->relatedTooltip(fn (?Author $author) => $author?->something())` —
  it receives the related model directly.
