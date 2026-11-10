# mdm-frontend Specification

## Purpose
TBD - created by archiving change mdm-frontend. Update Purpose after archive.
## Requirements
### Requirement: MDM navigation group and manifest pages

The OpenRegister frontend SHALL expose the steward-facing MDM surface as four
in-app pages grouped under a single navigation section labelled "Data quality".
`src/manifest.json` SHALL declare, in its `pages[]` array, four entries — Data
Quality dashboard, Duplicate Candidates, Master entities, and Queue / sync
health — each a `type: "custom"` page bound to its own view component and route.
`src/manifest.json` SHALL declare, in its `menu[]` tree, one group entry whose
`children[]` link to those four routes, each child carrying an `id`, `label`,
`icon`, `route`, and `order`. Every nav icon referenced by the group SHALL be
imported from `vue-material-design-icons/*` and registered as a global component
in `src/main.js`. No existing page, menu entry, or route SHALL be removed or
renamed.

#### Scenario: MDM group appears in the app navigation

- **WHEN** a steward opens the OpenRegister app
- **THEN** a "Data quality" navigation group MUST be present
- **AND** it MUST contain exactly four entries linking to the Data Quality
  dashboard, Duplicate Candidates, Master entities, and Queue / sync-health views
- **AND** each entry's icon MUST resolve to a registered
  `vue-material-design-icons` component (no missing-icon placeholder)

#### Scenario: Each MDM route renders its own view component

- **WHEN** the steward activates any of the four MDM navigation entries
- **THEN** the router MUST navigate to the corresponding `type: "custom"` page
- **AND** that page's bound view component MUST mount without console errors

#### Scenario: No existing navigation is disturbed

- **WHEN** the manifest is loaded after this change
- **THEN** every pre-existing `pages[]` entry and `menu[]` entry MUST still be
  present with an unchanged `id` and `route`

### Requirement: Register/schema selector drives every MDM view

Each MDM view SHALL begin with a shared register-then-schema selector built from
`NcSelect`. Each `NcSelect` SHALL carry an `inputLabel` (or
`ariaLabelCombobox`) prop and SHALL NOT rely on a manual `<label>` element. The
selected `(register, schema)` pair SHALL be held in the `quality` Pinia store so
that switching between MDM views preserves the selection. No view SHALL issue a
data request until both a register and a schema are selected.

#### Scenario: Schema select is disabled until a register is chosen

- **WHEN** an MDM view first renders with no register selected
- **THEN** the schema `NcSelect` MUST be disabled or empty
- **AND** no quality/duplicate/master/webhook request MUST be issued

#### Scenario: Selection persists across MDM views

- **WHEN** the steward selects a register and schema on the Data Quality view and
  then navigates to the Duplicate Candidates view
- **THEN** the Duplicate Candidates view MUST show the same register and schema
  pre-selected

#### Scenario: NcSelect carries an accessible label

- **WHEN** the register or schema `NcSelect` is rendered
- **THEN** it MUST expose an `inputLabel` (or `ariaLabelCombobox`) so that
  assistive technology announces its purpose

### Requirement: Data Quality dashboard

The Data Quality dashboard view SHALL, for the selected `(register, schema)`,
call `GET /api/objects/quality/{register}/{schema}/stats` and render KPI cards
for the average score and the good / fair / poor bucket counts, plus the
10-bucket score histogram from the response `histogram` array. It SHALL also
call `GET /api/objects/quality/{register}/{schema}` and render the lowest-quality
objects as a table showing each item's `id`, `qualityScore`, and
`qualityStatus`, honouring the endpoint's `limit` / `offset` pagination. When the
schema has no scored objects, the view SHALL render an explicit empty state
rather than a blank panel. The histogram data SHALL be presented; if a chart
primitive is unavailable it MAY degrade to a bucket table.

#### Scenario: KPI cards and histogram reflect the stats envelope

- **WHEN** a register and schema with scored objects are selected
- **THEN** the average-score card MUST show the `average` value from the stats
  response
- **AND** three bucket cards MUST show the `buckets.good`, `buckets.fair`, and
  `buckets.poor` counts
- **AND** the histogram MUST present all 10 values from the `histogram` array

#### Scenario: Lowest-quality table lists scored objects

- **WHEN** the lowest-quality listing returns items
- **THEN** the table MUST show one row per item with its `id`, `qualityScore`,
  and `qualityStatus`
- **AND** paging controls MUST advance via the endpoint's `limit` / `offset`

#### Scenario: Empty state on an unscored schema

- **WHEN** the selected schema has no scored objects (`total` is 0)
- **THEN** the view MUST render an explicit empty-state message and MUST NOT
  render an empty or broken chart

### Requirement: Duplicate Candidates view (read-only)

The Duplicate Candidates view SHALL, for the selected `(register, schema)`, call
`GET /api/objects/duplicates/{register}/{schema}` and render a paginated table of
candidate pairs, one row per item showing `objectA`, `objectB`, the similarity
`score`, and the `matchedOn` attribute list, honouring the endpoint's `limit` /
`offset` / `total`. The view SHALL be strictly read-only: it SHALL NOT expose a
merge, delete, or any write action. Merge execution is out of scope for this
change.

#### Scenario: Candidate pairs render with score and matched attributes

- **WHEN** a register and schema with duplicate candidates are selected
- **THEN** the table MUST show one row per candidate pair with `objectA`,
  `objectB`, `score`, and the `matchedOn` attributes

#### Scenario: No merge or write action is present

- **WHEN** the Duplicate Candidates view is rendered
- **THEN** there MUST be no merge, delete, or other write control on the view

#### Scenario: Pagination follows the endpoint envelope

- **WHEN** the candidate list `total` exceeds the page `limit`
- **THEN** the view MUST offer pagination that advances via `offset`

### Requirement: Master-entity list with golden-record detail

The Master-entity list view SHALL, for a selected survivorship-enabled
`(register, schema)`, list the schema's objects (via the existing object-list
read / `useObjectStore`) with `qualityScore` and `qualityStatus` columns, sorted
or filterable by quality. Selecting a master entity SHALL open a golden-record
detail panel that renders the object's materialised `attributeProvenance` map —
for each attribute, which source won and (where present) at what confidence /
timestamp. The detail SHALL be a panel, not a modal. The view SHALL read the
`attributeProvenance` map defensively, rendering whatever provenance keys the
merged survivorship engine produced.

#### Scenario: Master entities show quality columns

- **WHEN** a survivorship-enabled register and schema are selected
- **THEN** the list MUST show one row per object with its `qualityScore` and
  `qualityStatus`

#### Scenario: Golden-record detail shows attribute provenance

- **WHEN** the steward selects a master entity that has a materialised
  `attributeProvenance` map
- **THEN** a detail panel MUST open showing, per attribute, the winning source
  from the provenance map
- **AND** the panel MUST NOT be implemented as an inline `NcModal` / `NcDialog`

#### Scenario: Missing provenance degrades gracefully

- **WHEN** a selected object has no `attributeProvenance` map
- **THEN** the detail panel MUST render an explicit "no golden-record provenance"
  message rather than erroring

### Requirement: Queue / sync-health view

The Queue / sync-health view SHALL surface OpenRegister's existing webhook
delivery telemetry as a queue-health summary without introducing a new queue
subsystem or (in this change) a new backend endpoint. It SHALL list configured
webhooks via `GET /api/webhooks`, and for each SHALL call
`GET /api/webhooks/{id}/logs/stats` to show delivered (`success`), failed, and
`pendingRetries` counts, and SHALL surface recent failed deliveries via
`GET /api/webhooks/logs?success=false`. When no webhooks are configured, the view
SHALL render an explicit empty state. The view SHALL be read-only within this
change (retry-execution controls are out of scope).

#### Scenario: Per-webhook health counts render

- **WHEN** at least one webhook with delivery logs exists
- **THEN** the view MUST show, per webhook, its delivered / failed /
  `pendingRetries` counts sourced from `webhooks#logStats`

#### Scenario: Recent failures are listed

- **WHEN** failed deliveries exist
- **THEN** the view MUST list recent failed deliveries from
  `GET /api/webhooks/logs?success=false`

#### Scenario: Empty state when no webhooks are configured

- **WHEN** no webhooks are configured
- **THEN** the view MUST render an explicit empty-state message and MUST NOT
  issue per-webhook stats requests

### Requirement: MDM Pinia store module

The frontend SHALL back all four MDM views with a single new Pinia store module
`src/store/modules/quality.js` exporting `useQualityStore`, registered in
`src/store/store.js`. It SHALL follow the existing OpenRegister store pattern
(Options-API `defineStore` + `@nextcloud/axios` + `generateUrl`, mirroring
`reports.js`) and SHALL NOT introduce a bespoke store base class. It SHALL hold
the selected register/schema and expose read actions for quality stats,
lowest-quality objects, duplicate candidates, master entities (via the object
read), the golden record, and webhook health. It SHALL source all server data
through these APIs — never from DOM data-attributes; any bootstrap value SHALL
come from `loadState` (`@nextcloud/initial-state`).

#### Scenario: Store is registered and read-only

- **WHEN** the app store initialises
- **THEN** `useQualityStore` MUST be registered in `src/store/store.js`
- **AND** its actions MUST perform only read (GET) requests against the MDM /
  webhook APIs

#### Scenario: No DOM-attribute data reads

- **WHEN** any MDM view or the quality store loads server data
- **THEN** it MUST NOT read server-provided data from a DOM data-attribute
  (`document.getElementById(...).dataset.*`)
- **AND** any bootstrap value MUST be obtained via `loadState`

### Requirement: i18n source strings

The MDM views SHALL wrap all user-facing labels, headings, empty-state messages,
and table headers for translation with English as the source-language string. No user-facing string SHALL be hardcoded outside the
translation mechanism, and no i18n key SHALL use a non-English source string.

#### Scenario: MDM strings are translatable with English source

- **WHEN** an MDM view renders a label, heading, or empty-state message
- **THEN** that string MUST be produced through the translation function with an
  English source string

### Requirement: Visual and e2e coverage for every new view

Every new MDM page/view SHALL be covered by a visual-regression spec and by
Playwright e2e coverage (ADR-004; hydra gate-26 visual coverage; gate-19 e2e
coverage). Each new view SHALL either carry a `@visual`-tagged visual-regression
spec or a reason-bearing `@visual exclude`; the four MDM views and the
golden-record detail SHALL be covered, not excluded. Each ADDED spec Scenario
SHALL be referenced by at least one Playwright e2e test. UI behaviour SHALL be
asserted through real interaction (selecting register/schema, navigating,
paging); store reads MAY be used only as assertions.

#### Scenario: Each MDM view has a visual-regression spec

- **WHEN** the visual-coverage gate runs over this change
- **THEN** each of the four MDM views plus the golden-record detail MUST have a
  `@visual`-tagged visual-regression spec (no `@visual exclude`)

#### Scenario: E2e coverage drives the UI, not the API

- **WHEN** the e2e suite exercises an MDM view
- **THEN** it MUST select the register/schema and interact through the rendered
  UI
- **AND** it MUST NOT substitute a direct API call for the UI interaction it is
  asserting

