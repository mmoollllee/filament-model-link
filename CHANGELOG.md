# Changelog

All notable changes to `mmoollllee/filament-model-link` will be documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
