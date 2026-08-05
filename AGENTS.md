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
| Modal/Form placeholder referencing a related model | `ModelLink::make()` — to-many references render one pill per entry |
| Cell needs many related models pilled at once | `ModelLinkColumn::make()->relationships([...])` |
| Form placeholder needs many related models pilled at once | `ModelLink::make()->relationships([...])` |
| Too many pills — collapse the rest into `+N` | `->maxPills(6)` on either component |
| Render an iterable of models as pills anywhere | `ModelReferencePresenter::renderPillChains($models)` |
| Hover feedback on linked pills | ships in the package stylesheet — do not hand-roll `hover:` classes |
| Value pill without a model (date, counter, status) | `Pill::make('01.08.2026')->color('danger')->icon(...)->url(...)` |
| Per-record status color on a model pill | `Pill::for($m)->color('danger')` (chain: only the target recolors) |
| Tooltip should show *related-model* data | `->relatedTooltip(fn ($related) => …)` |
| Cell should edit its links without losing pill navigation | `ModelLinkColumn::make()->action(Action::make(…))` |
| Pill needs a record-specific icon (e.g. user with role icon) | `Pill::for($m)->icon(...)->iconTooltip(...)->toHtml()` |
| `Select::options()` populated with model pills | `ModelReferencePresenter::modelSelectOptions(...)` + `->allowHtml()` |
| Single Select with pill options | `modelSelectOptions()` + `->allowHtml()` |
| `->multiple()` Select with pill options | `modelSelectOptions()` + `->allowHtml()` + `->native(false)` + **import the package stylesheet** |
| Plain-text labels (native select, export, notification) | `modelSelectLabels()` |
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
  in `setUp()`. Without it, the cell click swallows the embedded `<a>`. The one
  exception is built in: `->action()` re-enables click-through and marks the
  pills with `x-on:click.stop`, so a pill click navigates while the rest of the
  cell opens the action. The same flag also lifts the stylesheet's
  `pointer-events: none` off a pill used as a select's selected value (via
  `fi-pill-clickthrough`) — use it for an editable table cell, not for a form
  select, where clicking a chip would leave the form. Dropdown options keep the
  suppression unconditionally; Filament needs that click.
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
- Styling pill hover in project CSS. The presenter marks linked pills with
  `.fi-pill-link` and the package stylesheet owns the hover; a project rule
  would fight it and would also re-apply hover inside dropdowns, where the
  click never navigates.
- **Emitting Tailwind utility classes from the presenter.** This package's PHP
  sits in the consumer's `vendor/`, which their Tailwind build does not scan —
  a utility only renders if their own app code happens to use the same class.
  `gap-1.5`, `items-stretch` and `rounded-none` did not, and chains shipped
  gapless and fully rounded until someone measured the built CSS. Markup carries
  `fi-*` hook classes only; visuals go in
  `resources/css/filament-model-link.css`. `MarkupCarriesNoTailwindTest`
  enforces this — do not weaken it, and add new hooks to the list there.
- Adding a custom icon contract / icon-method requirement (e.g. `iconName()`)
  to `HasPills`. Icon source is intentionally a resolver concern.
- Using `->tooltip(fn ($record) => $record->author?->something())` for a
  related-model column. Use `->relatedTooltip(fn (?Author $author) => $author?->something())` —
  it receives the related model directly.
- Shipping pills in a `->multiple()` Select without importing the package
  stylesheet — the result is a visibly doubled badge. Import it in the panel
  theme, and set `->native(false)`.
- Reaching for `->getOptionLabelFromRecordUsing()` to style options of a
  `->multiple()` select. It feeds Filament's `getOptionLabelsUsing()`, so it
  also labels the selected values. (`options()` does too — select.js seeds its
  label repository from the options array — so the reason is not *where* the
  pill lands but that `getOptionLabelFromRecordUsing()` also fires the
  relationship query per value.) Use `options()` / `getSearchResultsUsing()`
  there, and import the stylesheet either way: the selected value always sits
  inside Filament's own badge. On a **single**
  select the reverse is true: no wrapper badge exists, and labelling the
  selected value is the point, so `->getOptionLabelFromRecordUsing()` is the
  correct tool on a `->relationship()` select — it leaves `createOptionForm()`
  and the relationship query alone.
- Passing a `$labelAttribute` to `modelSelectOptions()` / `modelSelectLabels()`
  just to get `name`. Both default to `basePillLabel()`, which honors
  `HasPillLabel`; an explicit attribute silently diverges from the pill text.
