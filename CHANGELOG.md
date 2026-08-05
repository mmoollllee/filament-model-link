# Changelog

All notable changes to `mmoollllee/filament-model-link` will be documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.2] — 2026-08-05

### Added

- **`Pill::clickthrough()`** — the fluent counterpart of the presenter's
  `stopClickPropagation:` flag, so a pill built through `Pill` can stay
  navigable as a select's selected value too. `renderPill()` and
  `renderPillWithIconOverride()` take the flag as well, which means every
  render path now supports it.

## [0.3.1] — 2026-08-05

### Fixed

- **A pill used as a select's selected value can be clickable again.** The
  stylesheet neutralises those pills (`pointer-events: none`), which also
  swallowed the pills of an inline multi-select cell. `stopClickPropagation:
  true` now marks the anchor `fi-pill-clickthrough`, and the stylesheet exempts
  exactly those from the suppression — dropdown list items keep it
  unconditionally, so the same label still selects as an option and navigates
  as a chip. Selected values of a form select are unaffected: without the flag
  they stay neutral, as before.

## [0.3.0] — 2026-08-05

### Added

- **Editable `ModelLinkColumn` cells.** `->action()` on the column now
  re-enables the cell click that `setUp()` disables, and renders every linked
  pill with `x-on:click.stop`. Clicking a pill navigates to that record as
  before; clicking anywhere else in the cell opens the action — previously the
  wrapper's `wire:click.prevent.stop` cancelled the pill's navigation, so the
  two were mutually exclusive.
- **`stopClickPropagation:` on `renderStandalonePill()`, `renderPillChain()`
  and `renderPillChains()`** — the opt-in behind the above. Off by default,
  and it must stay off for pills inside a select dropdown, where the click has
  to reach Filament's own handler to pick the option.

## [0.2.0] — 2026-07-23

### Added

- **`ModelReferencePresenter::renderPillChains(iterable $models, ?int $labelLimit, ?int $maxPills)`** —
  renders any iterable of models (Eloquent Collection, array, …) as pill
  chains inside one flex-wrap container (`.fi-pill-chains`). Skips non-model
  entries, renders exact duplicates (same class + key) once, drops models that
  already appear as a chain ancestor of another entry, and returns `''` when
  nothing is renderable. `$maxPills` collapses the remainder into a `+N`
  overflow pill whose `title` lists the hidden labels; non-positive values
  mean unlimited. Guards against pathological parent cycles by falling back
  to rendering every entry instead of nothing.
- **`ModelLink` to-many support.** A reference name that resolves to a
  collection (e.g. `tags.name` on a `BelongsToMany`) renders one pill chain
  per related model instead of throwing a `TypeError`. An empty collection
  renders nothing, matching the to-one null behavior.
- **`ModelLink::relationships([...])`** — multi-relation mode on the form
  component, parity with `ModelLinkColumn`.
- **`maxPills(?int)`** on both `ModelLink` and `ModelLinkColumn`.
- **`ModelReferencePresenter::renderPill(label, ?color, ?icon, ?url, iconTooltip)`** —
  raw value pills (dates, counters, statuses) with no model behind them,
  visually identical to model pills.
- **`Pill::make(string $label)`** — model-less builder entry point for raw
  pills; `linked()`/`asChain()` require a model and are ignored there.
- **`resources/css/filament-model-link.css`** — theme stylesheet, imported once
  in the panel theme (publishable via `filament-model-link-styles`). Makes pills
  work inside Filament selects: Filament's select.js wraps every selected value
  in an `fi-color-primary` badge (`createBadgeElement`), so a pill lands inside
  a badge. No PHP hook can avoid it — the badge label comes from the loaded
  options array, and `getOptionLabelsUsing()` is only consulted for values
  missing from it. The CSS flattens that wrapper, scoped with `:has()` so
  selects without pills keep Filament's normal styling, and removes the link
  affordance from dropdown pills, where Filament's click handler intercepts the
  click and the `<a>` can never navigate.
- **`ModelReferencePresenter::modelSelectLabels(Collection, ?labelAttribute, ?labelCallback)`** —
  plain-text counterpart to `modelSelectOptions()`, keyed identically, honoring
  `HasPillLabel`. For native selects, exports and notifications — anywhere HTML
  is not an option.
- **`Pill::color(?string)`** and a `$color`/`$targetColor` override on
  `renderStandalonePill()`, `renderPillWithIconOverride()` and
  `renderPillChain()` — per-record status colors (e.g. an overdue date in
  `danger`) without touching the model's `HasPills` color. On chains only
  the target segment is recolored.

### Fixed

- **`modelSelectOptions()` ignored `HasPillLabel`.** It read
  `$model->{$labelAttribute}` with `'name'` as the default, so a model whose
  pill label differs from its `name` (e.g. `"186-43316 (Liebherr 32TT)"`)
  rendered a different label in select options than everywhere else — and
  models sharing a `name` became indistinguishable in the dropdown. The
  parameter is now nullable and defaults to `basePillLabel()`, matching every
  other rendering path; passing an attribute or callback still overrides it.
  This is exactly the anti-pattern the package's own guidelines forbid.
- **`ModelLinkColumn` pointed at a to-many relation crashed.** The single-relation
  render path fed the resolved collection straight into `renderPillChain()`,
  which is typed against `Model`. Collections now route through
  `renderPillChains()`; the placeholder still renders when the collection is
  empty.
- **Multi-relation `ModelLinkColumn` mixing in a to-one relation crashed or
  leaked raw attributes.** `Collection::merge()` flattens an `Arrayable` model
  into its attribute array, so a `BelongsTo` entry in `relationships([...])`
  produced scalars that broke ancestor de-duplication. Entries are now
  normalized per relation before merging — to-one and to-many relations can
  be mixed freely.
- **Pill chains rendered as separate, fully rounded pills without gaps.** The
  chain wrapper carried Tailwind arbitrary variants
  (`[&>*]:!rounded-none`, `[&>*:first-child]:!rounded-s-md`, `items-stretch`)
  and the multi-chain wrapper carried `gap-1.5` — but this package's PHP sits in
  the consumer's `vendor/`, which their Tailwind build does not scan, so those
  utilities were never generated. Measured in a real build: `flex`,
  `inline-flex` and `items-center` existed only because the app used them
  elsewhere; `gap-1.5`, `items-stretch` and `rounded-none` did not exist at all.
  Chain geometry and spacing now live in the package stylesheet as plain CSS.
  The radius rules use `.fi-pill-chain > .fi-badge` so they outrank Filament's
  own `.fi-badge { rounded-md }` regardless of a consumer's import order.
- **Pills used as a select value sat off-centre and made the row too tall.** A
  pill is an inline-flex box, so inside one of Filament's text containers it is
  aligned on that container's text baseline — and the strut inherited from
  `.fi-select-input-btn` (`text-sm` / `leading-6`) reserves more space below the
  baseline than the pill needs. Measured in Chromium against a real select DOM,
  identical across system-ui, Helvetica and Georgia:

  |                      | before             | after |
  | -------------------- | ------------------ | ----- |
  | single select row    | 37px               | 36px  |
  | single select pill   | 6px top / 11px bot | 8/8   |
  | multi select row     | 38px               | 36px  |
  | multi wrapper badge  | 26px               | 24px  |
  | multi select pill    | 8px top / 10px bot | 8/8   |

  36px is Filament's standard input height, so the rows now match a plain-text
  select exactly. The stylesheet zeroes the container's line-height — which
  removes the strut — and aligns the pill on the text middle, when and only when
  the container holds a pill. `display: flex` on the container was tried first
  and rejected: it makes the pill a flex item, whose own `justify-content:
  center` then clips an over-long label at *both* ends, cutting off the leading
  characters that identify the record, and it disables the `text-overflow`
  Filament sets on that container.
- **The linked-pill hover ring was clipped on its inline-start edge** inside a
  select's value container, and would have replaced Filament's own badge ring.
  It is now an `outline` with a negative offset: drawn inside the border box, so
  no ancestor can crop it; a separate property from `box-shadow`, so it stacks
  with `.fi-badge`'s ring instead of overriding it; and it follows
  `border-radius`, so a middle chain segment gets a matching square ring for
  free. The press state dropped its `translateY` — inside a chain it shifted one
  segment against its neighbours.
- **Pills carried no text color of their own.** `badgeClasses()` hand-wrote
  `fi-color fi-color-X`, but `.fi-badge.fi-color` resolves `color: var(--text)`
  and `--text` is set *only* by the `fi-text-color-{shade}` classes Filament
  derives per color from WCAG contrast. Without them the declaration is invalid
  at computed-value time and the pill inherited the ambient color — so the same
  model rendered one color in a Filament badge and another in a pill. The class
  list now delegates to `FilamentColor::getComponentClasses(BadgeComponent)`,
  merged with `fi-color-X` so existing CSS hooks keep working.
- **`ModelLinkColumn`'s placeholder emitted Tailwind utilities** — the exact
  thing this release bans. `text-sm text-gray-400 dark:text-gray-500` never
  rendered in a consumer build (measured: `.text-gray-400` occurs zero times in
  one consuming app's theme), so "no related record" showed at full body size
  and color. It now carries `.fi-pill-placeholder`, styled in the stylesheet.
  `MarkupCarriesNoTailwindTest` never sampled this path.
- **A blank icon still emitted its tooltip span.** `renderPillWithIconOverride()`
  wrapped the icon unconditionally, so `Pill::for($m)->iconTooltip('…')` with no
  icon shipped `<span class="fi-pill-icon" title="…"></span>` — an empty flex
  item consuming `.fi-badge`'s column gap, with a tooltip on a zero-width box.
  Both icon paths now share one `iconSpan()` helper that returns `''`.
- **The hover ring snapped instead of easing.** The transition listed
  `outline-color`, but the base state declared no outline, so `outline-style`
  flipped `none` → `solid` discretely — and that property is not animatable. The
  base now declares a transparent ring and only the color changes.
- **Selected select values carried a live link.** select.js seeds its label
  repository from the options array, so a selected badge reuses the dropdown's
  markup verbatim — anchor included — inside the select's own `<button>`.
  Clicking a chip to remove it navigated away mid-edit. Anchors in the value
  container are now neutralised like the dropdown's, and the dropdown reset
  gained `:focus-visible`, which it had been missing for keyboard users.
- **The alignment fix covered only selects.** A pill injected into a table cell
  or infolist entry through `formatStateUsing()` sat on that container's strut
  too. Filament fixes this for its own badges with
  `.fi-ta-text-item.fi-ta-text-has-badges > .fi-badge { vertical-align: middle }`,
  but only sets that marker class when `->badge()` was called. `.fi-ta-text-item`
  and `.fi-in-text-item` now get the same treatment; measured, the container
  collapses to the pill's own height with a 0/0 offset.
- **The `!important` gray-950 override inside selects became wrong.** It existed
  to stop the wrapper badge's `fi-color-primary` cascade tinting the pill — a
  workaround for the missing `fi-text-color-*` classes. With those now emitted,
  the pill sets its own color and the override forced the wrong one: measured,
  a table badge rendered `rgb(37,99,235)` while the same pill in a select
  rendered `rgb(3,7,18)`. Removed; both now render `rgb(37,99,235)`.
- **The documented `pill_chain_max_depth` did not match the shipped one.** The
  default was deliberately lowered to 4 in v0.1.1, but README, the AI guidelines
  and the `?? 5` fallback in `pillChainModels()` kept saying 5 — and because
  `mergeConfigFrom()` always resolves the packaged file, that fallback could
  never fire to reveal the mismatch. The default stays 4; the docs and the
  fallback now say so.

### Changed

- **Presenter markup carries `fi-*` hook classes only.** No Tailwind utilities
  are emitted from the package any more (see Fixed). New hook: `.fi-pill-icon`
  on the tooltip span, replacing `inline-flex`. `renderPillWithIconOverride()`
  also dropped its redundant `inline-flex items-center gap-1.5` wrapper around
  icon + label — `.fi-badge` is already an inline-flex row with its own gap,
  exactly as `renderStandalonePill()` has always relied on.
  `MarkupCarriesNoTailwindTest` pins the rule so it cannot silently return.
- **The stylesheet import is no longer optional.** It was previously described
  as needed only for pills inside selects; chain geometry now depends on it too.
- **Linked pills no longer carry `transition hover:underline` in the markup.**
  They get `.fi-pill-link` instead, and the stylesheet owns the hover: a
  button-like surface reaction (`brightness()` + a `currentColor` ring) rather
  than an underline, since a pill reads as a button. Suppressed inside dropdown
  lists, where Filament intercepts the click. Projects importing the stylesheet
  gain the new hover automatically; projects that do not import it lose the old
  underline.
- `ModelLinkColumn`'s multi-relation rendering delegates to
  `renderPillChains()`; the de-duplication logic lives in the presenter and
  the emitted wrapper carries the `fi-pill-chains` hook class.

## [0.1.1] — 2026-06-25

### Changed

- `FilamentModelLinkPlugin` is idempotent, so the same plugin instance can be
  registered in several panels without re-wiring the resolvers.
- Lowered the default `pill_chain_max_depth` from 5 to 4.
- Simplified rendering and resource resolution (quality pass).

### Fixed

- Correctness bugs found in code review; the icon tooltip now survives the
  fallback-icon path.

### Documentation

- `resolveResourceUsing()` documented for multi-panel apps.

## [0.1.0] — Initial release

### Added

- **Contracts** `HasPills`, `HasPillLabel`, `HasPillParent`.
- **`ModelReferencePresenter`** — static API:
  - `renderStandalonePill`, `renderPillChain`, `renderPillWithIconOverride`
  - `pillChainModels`, `textChain`, `basePillLabel`
  - `modelSelectOptions`, `enumSelectOptions`, `renderEnumOption`
  - `urlForRelated`, `url`, `resolveRelatedRecord`, `displayLabel`, `typeBasename`
  - `defaultViewTypes()` — reads `config('filament-model-link.default_view_types')`
- **`Pill`** fluent builder, `Htmlable` for direct Blade interpolation.
- **`ModelLink`** Filament form component (Placeholder).
- **`ModelLinkColumn`** Filament table column:
  - Single-relation pill chain
  - Multi-relation mode with ancestor de-duplication
  - `->relatedTooltip(Closure)` receives the resolved related model
  - `->viewTypes()`, `->overwriteName()`, `->setViewType()`
  - Multi-relation state rebuilt as joined labels so search/sort work
- **`FilamentModelLink`** fluent top-level configurator.
- **`FilamentModelLinkPlugin`** Filament plugin wrapper for panel-scoped install.
- **Hooks**:
  - `resolveIconUsing(Closure)`
  - `resolveResourceUsing(Closure)`
  - `resolveResourceParametersUsing(Closure)`
  - `registerCustomUrlResolver(Closure)`
  - `flush()` for test isolation
- Config file, all keys honored at runtime: `pill_chain_max_depth`,
  `ancestor_label_limit`, `default_color`, `default_view_types`. The latter
  drives the default action order in `url()` / `urlForRelated()` and the
  `ModelLink` / `ModelLinkColumn` components (override per-call via
  `viewTypes()` / `setViewType()`).
- Laravel Boost-style agent guidelines at `.ai/guidelines/filament-model-link.md` (publishable).
- 27 unit tests (Pest + Orchestra Testbench).
- GitHub Actions CI: test matrix across PHP 8.2 / 8.3 / 8.4 (+ a `prefer-lowest`
  job validating the dependency floors), PHPStan, and Pint jobs.
- PHPStan (Larastan) **level 6**, green with no baseline or suppressions.
  `composer test` / `composer analyse` / `composer format` scripts.

### Fixed

- **Multi-relation `ModelLinkColumn` search/sort was broken.** The rebuilt
  `state()` closure referenced an uncaptured `$relationships` variable
  (always `null` at runtime), so the joined-label state never populated.
  Now reads `$this->relationships` and guards each item with `instanceof Model`.
  Caught by PHPStan, missed by tests.

### Changed

- Tightened `illuminate/contracts` to `^11.28|^12.0|^13.0` to match the actual
  Filament v5 floor (was `^11.0`).
