# Pill select — behavior definition, status and open ideas

This document does three things:

1. **Definition** — a framework-agnostic description of how a record-assignment
   field should behave. It is written so it can be lifted into a non-Filament
   app (a Vue component, say) unchanged.
2. **Status** — how this package covers the definition, and what was checked in
   a real browser rather than assumed.
3. **Open ideas** — what is still missing, as user stories.

## 1. Definition

An *assignment field* picks one or many records and shows the result as pills.

### Assigned values

- Every assigned record is a **pill**: type icon + label, in the type's color.
- A pill is a **link to the record**. Clicking it navigates. It never opens the
  dropdown.
- Hovering (or focusing) a pill reveals a **remove control**. Clicking it
  unassigns that record and does nothing else. Where there is no hover (touch),
  the control is always visible.

### The field

- The **free area** of the field — everything that is not a pill or its remove
  control — is the only click target that opens the dropdown. The keyboard opens
  it too (focus, then typing or arrow down).
- The dropdown is a **searchable suggestion list**: typing filters it, on the
  server when the set is large.
- Every suggestion renders as the **same pill** as an assigned value.
- **Inside the dropdown the links are inert.** A click on a row selects it and
  never navigates; the row highlight is the only hover affordance, not a link
  hover.

### The same field in columns

- A table cell shows the same pills — same links, same colors, same icons.
- An editable column offers the same behavior as the form field: pill click
  navigates, remove control on hover, free area opens the searchable list.
- One render function per record type feeds every place (dropdown, assigned
  value, cell), so they cannot drift apart.

### Type styling

- Each record type has an **app-wide color and icon**, defined once — not per
  field.
- A record may override that where its identity is the point. A **customer**
  takes its icon from the customer's favicon and its color from the customer's
  primary color. A customer without either falls back to the type's color and
  icon.

## 2. Status

| Definition | Status | Where |
|---|---|---|
| Assigned values as record-linked pills | Covered | `Select::pillOptions()`; links survive as selected values via click-through (default on). |
| Pill click navigates, does not open the dropdown | Covered | The click-through pill stops the click before the select sees it. |
| Free area opens the dropdown | Covered | Filament's own select behavior. |
| Dropdown links inert, no link hover | Covered | Stylesheet suppresses pointer events and hover on anchors inside the dropdown and the select value. |
| Same pill in dropdown and as assigned value | Covered | `pillOptions()` sets both label sources from one renderer. |
| Remove control revealed on hover | Covered | Stylesheet; hover and `:focus-within`, gated on `(hover: hover)` so touch keeps it visible. |
| Searchable suggestion list with pill results | Covered | `pillOptions()` searches the visible text on the server; `searchUsing:` for big sets; `searchable:` forces the flag. |
| Same field in columns | Covered | `SelectColumn::pillOptions()` for one assignment, `MultiSelectColumn` for several — ported from nest.kuckuck.cam. |
| App-wide color per type | Covered | `HasPills::color()` — a palette name. |
| App-wide icon per type | Covered | The icon resolver. |
| Per-record icon / color (customer: favicon + primary color) | Covered | `resolveStyleUsing()` returning a `PillStyle`; free colors; image icons with a type-icon fallback. |
| Keyboard | Covered | Backspace in an empty search removes the last pill; Backspace / Delete on a focused pill removes it. The rest is Filament's. |

### Checked in a real browser

The behavior above was exercised against a throwaway Filament 5 panel in
headless Chrome — a form (relationship and options selects, single and
multiple) and a table with an editable `SelectColumn`:

- the × is invisible at rest, appears on chip hover and on keyboard focus;
- a favicon renders, and a favicon that 404s is swapped for the type icon;
- free brand colors render legibly in light and dark, pale and dark ones
  included;
- a search for `href` keeps all 6 options with the static API (the bug) and
  none with `pillOptions()`; a search for `ini` narrows to the one customer;
- Backspace in an empty search removes the last pill; with text in the box it
  only edits the text; Backspace on a focused pill removes that pill;
- clicking a pill navigates, clicking the free area opens the dropdown, clicking
  a dropdown row selects it without navigating, and a `SelectColumn` pick
  survives a reload;
- an empty options list — static or from a closure — still opens a panel that
  says "No options available.";
- Delete on a focused pill removes it, as Backspace does, and Backspace on a
  single select's pill does nothing; with touch emulation (no hover) the × stays
  visible;
- the inline `MultiSelectColumn` (Filament 5.9): only the selected pills are in
  the page; the free area opens the unassigned records; a search for `href`
  finds nothing; a pick, a × and Backspace each save at once and survive a
  reload; a JSON array column saves too; a pill in the cell navigates;
- a broken favicon keeps its fallback icon through a Livewire re-render, and a
  slot that a re-render hands a working favicon shows it again.

The same checks were repeated in Firefox and WebKit (Playwright): all pass, with
one browser quirk outside the package — WebKit navigates back when Backspace is
pressed on a focused link, which is what happens on a pill in a *single* select;
on a `->multiple()` select the package's handler prevents it and removes the pill
instead. nest.kuckuck.cam's own browser tests of the inline column pass in
Chrome, Firefox and Safari.

Not checked: a physical touch device (touch was emulated in Chrome).

## 3. Open ideas

### A shorter debounce for an in-memory search

As a user, I want the suggestion list to narrow while I am still typing when the
options are already in memory, so that a search over twenty customers does not
wait a second.

- Filament debounces any server search by one second, and `pillOptions()` leaves
  that alone because the macro cannot tell whether the caller already set one.
- `->searchDebounce(300)` shortens it per field today.
