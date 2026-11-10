# Capability: `mdm-views-route-scoping`

## Purpose

Make OpenRegister's MDM "Data quality" views route-scoped and deep-linkable.
The shared `RegisterSchemaSelector` reads a `?register=` / `?schema=` query on
mount and mirrors every selection change back into the hash-mode route, so a
steward can bookmark and share a specific register/schema view and an
automated test can deterministically land on a known dataset with no clicks.
The `quality` store remains the source of truth; the route is a mirror and an
entry point.

## ADDED Requirements

### Requirement: The MDM Data-quality views MUST be route-scoped and deep-linkable

The shared `RegisterSchemaSelector` SHALL, on mount, adopt a `?register=` (and
optional `?schema=`) route query when present — committing it to the `quality`
store, populating the selects, loading the register's schemas, and (when both
are present) triggering the view's data load — so a deep-link auto-selects and
loads with no user interaction. When no route query is present the selector
SHALL restore the persisted store selection (unchanged behaviour) and mirror it
into the route. Every in-UI selection change SHALL be reflected into the route
query via `history.replaceState`-style navigation (`$router.replace`), with a
`NavigationDuplicated` rejection swallowed. The store SHALL remain the source of
truth; the route is a mirror written on change and read once on mount.

#### Scenario: Deep-link preselects register and schema
- **GIVEN** a scored register `R` and schema `S`
- **WHEN** the steward opens `#/quality?register=R&schema=S`
- **THEN** the register select shows `R` and the schema select shows `S` without any clicks
- **AND** the Data Quality dashboard loads that pair's statistics

#### Scenario: Route query takes precedence over stored selection
@e2e exclude selector-internal precedence; asserted indirectly by the deep-link preselect e2e (a distinct assertion needs a pre-seeded conflicting store selection, which the shared store resets on each page load) + covered by the quality-store unit test
- **GIVEN** the `quality` store already holds a selection for register `A`
- **WHEN** the steward opens a view with `?register=B&schema=T`
- **THEN** the selector adopts `B`/`T` (the route query), not the stored `A`

#### Scenario: Selecting a register and schema updates the URL
- **GIVEN** the steward is on a Data-quality view with no query
- **WHEN** they pick a register and then a schema in the selector
- **THEN** the route query becomes `?register=<id>&schema=<id>`
- **AND** reloading the page restores the same selection from the URL

#### Scenario: Changing the register resets the schema in the URL
@e2e exclude selector-internal reset; the schema-cleared state is asserted by the "schema select is disabled until a register is chosen" e2e and the quality-store `setSelection` unit test
- **GIVEN** a register and schema are selected and mirrored into the URL
- **WHEN** the steward changes the register
- **THEN** the schema selection is cleared in both the store and the URL query
- **AND** no data request is issued until a schema is chosen again

#### Scenario: Selects expose stable test handles
- **GIVEN** any MDM Data-quality view
- **WHEN** the register/schema selector renders
- **THEN** the register select carries `data-testid="mdm-register-select"`
- **AND** the schema select carries `data-testid="mdm-schema-select"`
