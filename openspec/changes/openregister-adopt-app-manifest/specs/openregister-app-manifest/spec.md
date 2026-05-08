# OpenRegister app-manifest capability spec

## ADDED Requirements

### Requirement: REQ-OR-MAN-001 Manifest file exists at canonical location

OpenRegister SHALL ship a `src/manifest.json` validated against the canonical
schema published by `@conduction/nextcloud-vue` per ADR-024 §1-2. The file
SHALL set `$schema` to the published GitHub raw URL of
`nextcloud-vue/src/schemas/app-manifest.schema.json`. The file MUST NOT fork
or duplicate the schema.

#### Scenario: Manifest exists and is loadable

- **WHEN** the OpenRegister Vue bundle is built
- **THEN** `src/manifest.json` is present, parses as valid JSON, and matches
  the canonical schema (passes the build-time `npm run check:manifest` gate)

#### Scenario: Schema is referenced, not duplicated

- **WHEN** a reviewer inspects `src/manifest.json`
- **THEN** the `$schema` field points at the canonical
  `nextcloud-vue/src/schemas/app-manifest.schema.json` URL and the OR repo
  contains no copy of that schema file

---

### Requirement: REQ-OR-MAN-002 Manifest declares zero Conduction-app dependencies

The manifest's `dependencies` field SHALL be an empty array `[]`. OpenRegister
is the platform foundation; it has no upstream Conduction-app dependencies.
This matches ADR-024 §10's explicit guidance for the foundation repo (the
only other app with `dependencies: []` is `mydash`, per the same ADR).

#### Scenario: Foundation declares no dependencies

- **WHEN** `src/manifest.json` is loaded
- **THEN** `manifest.dependencies` is the empty array `[]`

#### Scenario: Loader skips dependency check on empty deps

- **WHEN** `useAppManifest('openregister', bundled)` runs and reads
  `dependencies: []`
- **THEN** `CnAppRoot`'s `dependency-check` phase is a no-op and the shell
  enters the `loading` → `shell` transition without rendering
  `CnDependencyMissing`

---

### Requirement: REQ-OR-MAN-003 Manifest declares 30 pages mapped from existing router

The manifest's `pages[]` SHALL contain exactly one entry per route currently
declared in `src/router/index.js` (30 routes today, excluding the catch-all).
Each entry SHALL set `id` (= route name), `route` (= path pattern), `type`
(closed enum: `index | detail | dashboard | custom`), and `title` (i18n key).

The page-type mapping SHALL follow the design.md table:

- 1 `dashboard` — `Dashboard` (`/`)
- 8 `index` — `Registers`, `Schemas`, `Sources`, `Objects`, `Search`,
  `Endpoints`, `Applications`, `Entities`
- 4 `detail` — `RegisterDetail`, `SchemaDetails`, `ApplicationDetails`,
  `EntityDetail`
- 17 `custom` — `Configurations`, `AuditTrail`, `SearchTrail`, `Webhooks`,
  `WebhookLogs`, `Templates`, `Agents`, `Chat`, `Files`, `Deleted`,
  `Organisations`, `AVG`, `Reports`, `ReportView`, `MyAccount`, plus the
  two log views

#### Scenario: Page count matches router

- **WHEN** the build runs `check:manifest`
- **THEN** `manifest.pages[].length === 30` and each `id` is unique

#### Scenario: Schema-driven pages reference OR resources by name

- **WHEN** an entry has `type:"index"` or `type:"detail"`
- **THEN** the entry's `config.{register, schema}` references the underlying
  OR register and schema by slug (no inline duplication of the schema body)

#### Scenario: Custom-typed pages declare a component

- **WHEN** an entry has `type:"custom"`
- **THEN** the entry sets `component` to a name registered in the consumer-
  supplied `customComponents` map

#### Scenario: Title is an i18n key

- **WHEN** an entry's `title` is read
- **THEN** the value matches the pattern `openregister.<key>` and resolves
  to a translation in `l10n/{en,nl}.js` per ADR-007 / ADR-025

---

### Requirement: REQ-OR-MAN-004 Manifest declares menu split between main and settings sections

The manifest's `menu[]` SHALL declare top-level navigation entries with
exactly two `section` values: `"main"` for primary nav (Dashboard, Registers,
Schemas, Objects, Search, Sources, Endpoints) and `"settings"` for the
configuration / log / settings cluster (Configurations, AuditTrail,
Settings).

Each menu entry SHALL set `id`, `label` (i18n key), `icon`, `route`, `order`,
and `section`.

#### Scenario: Main and settings sections are populated

- **WHEN** the manifest is loaded
- **THEN** `manifest.menu[]` contains entries with `section: "main"` and
  entries with `section: "settings"`, with no other section value

#### Scenario: Menu order is monotonic per section

- **WHEN** entries within a section are sorted by `order`
- **THEN** the visible order in the rendered nav matches the manifest order
  exactly

#### Scenario: Menu labels are i18n keys

- **WHEN** a menu entry's `label` is read
- **THEN** the value matches `openregister.<key>` and resolves via the app's
  `t()` function

---

### Requirement: REQ-OR-MAN-005 Loader is wired in main.js (Tier 1)

`src/main.js` SHALL `import bundled from './manifest.json'` and call
`useAppManifest('openregister', bundled)` per ADR-024 §3. This wiring SHALL
land at Tier 1 even though the return value is not yet consumed by the
shell — the call is the foundation that Tier 2's `CnPageRenderer`
integration builds on.

#### Scenario: Loader is invoked on bootstrap

- **WHEN** the OR Vue app mounts
- **THEN** `useAppManifest('openregister', bundled)` has been called and
  the resulting reactive `manifest` ref is available in app scope

#### Scenario: Backend 404 falls back silently

- **WHEN** `/index.php/apps/openregister/api/manifest` returns 404 (the
  endpoint is deferred)
- **THEN** the loader keeps the bundled manifest, emits no console error,
  and the app boots normally

#### Scenario: Schema validation failure is logged but non-fatal

- **WHEN** the manifest fails validation against the canonical schema
- **THEN** `validateManifest()` logs a `console.warn` and the bundled
  manifest is preserved (per `useAppManifest.js:99-115` behaviour)

---

### Requirement: REQ-OR-MAN-006 CnPageRenderer drives schema-driven routes (Tier 2)

For the 8 schema-driven page pairs (`Registers`, `Schemas`, `Sources`,
`Objects`, `Search`, `Endpoints`, `Applications`, `Entities`), `src/router/
index.js` SHALL dispatch through `CnPageRenderer` instead of direct
component imports. The renderer matches the current vue-router route by
`name` against `pages[].id` and dispatches by `page.type` to a `pageTypes`
registry — defaults `index` / `detail` / `dashboard` for these 8.

`type:"custom"` routes SHALL continue to dispatch through their existing
view components, registered in a `customComponents` map passed to the
renderer.

#### Scenario: Index route dispatches via renderer

- **WHEN** the user navigates to `/registers`
- **THEN** the route resolves to `CnPageRenderer` keyed by `id: "Registers"`
  with `type: "index"`, which renders the schema-driven index view using the
  manifest's `config.{register, schema, columns}`

#### Scenario: Detail route dispatches via renderer

- **WHEN** the user navigates to `/registers/abc-123`
- **THEN** the route resolves to `CnPageRenderer` keyed by
  `id: "RegisterDetail"` with `type: "detail"`, which renders the schema-
  driven detail view with `objectId = "abc-123"`

#### Scenario: Custom route dispatches via customComponents

- **WHEN** the user navigates to `/audit-trails`
- **THEN** the route resolves to `CnPageRenderer` keyed by
  `id: "AuditTrail"` with `type: "custom"`, which looks up `component:
  "AuditTrailIndex"` in the `customComponents` map and renders that

#### Scenario: Dashboard route dispatches via renderer

- **WHEN** the user navigates to `/`
- **THEN** the route resolves to `CnPageRenderer` keyed by
  `id: "Dashboard"` with `type: "dashboard"`, which renders the
  widget-grid layout from `manifest.pages[].config.{widgets, layout}`

---

### Requirement: REQ-OR-MAN-007 Build gate validates the manifest

The OR `package.json` SHALL declare a `check:manifest` script that runs the
library's `validateManifest` CLI against `src/manifest.json` per ADR-024 §5.
The script SHALL be invoked from CI's lint stage and SHALL fail the job on
schema errors.

#### Scenario: Valid manifest passes the gate

- **WHEN** CI runs `npm run check:manifest` on a valid `src/manifest.json`
- **THEN** the script exits 0 and the job continues

#### Scenario: Invalid manifest fails the gate

- **WHEN** CI runs `npm run check:manifest` on a manifest with a schema
  violation (missing required field, unknown `type`, duplicate `id`, etc.)
- **THEN** the script exits non-zero, prints the validation error path
  inside the JSON, and the job fails

#### Scenario: Gate is wired into composite scripts

- **WHEN** `npm run check` or `npm run check:strict` is invoked
- **THEN** `check:manifest` runs as part of the composite

---

### Requirement: REQ-OR-MAN-008 Manifest version reflects the adoption tier

The manifest's top-level `version` SHALL follow semver of content per
ADR-024 §7. While the underlying page-type enum question is unresolved
(Open Question 2 in design.md), `version` SHALL stay sub-1.0.0:

- `0.1.0` — Tier 1 only (loader wired; manifest declared; renderer not yet
  driving dispatch)
- `0.2.0` — Tier 2 wired through (renderer dispatches schema-driven routes)

A bump to `1.0.0` SHALL NOT happen until either (a) the page-type enum is
extended upstream so OR's custom share drops below 50%, or (b) ADR-024 §7
is amended to allow stable consumers to declare 1.0.0 with a high custom
share.

#### Scenario: Tier 1 ships at 0.1.0

- **WHEN** Phase A lands
- **THEN** `manifest.version` is `"0.1.0"`

#### Scenario: Tier 2 ships at 0.2.0

- **WHEN** Phase B lands
- **THEN** `manifest.version` is `"0.2.0"`

#### Scenario: Sub-1.0.0 stays until upstream resolves

- **WHEN** a reviewer questions the sub-1.0.0 version
- **THEN** the answer references Open Question 2 in design.md and ADR-024 §7

---

### Requirement: REQ-OR-MAN-009 Backend `/api/manifest` endpoint is deferred

OpenRegister SHALL NOT implement `GET /index.php/apps/openregister/api/
manifest` as part of this change. The composable's silent fallback on 404
makes absence non-regressive. A follow-up change driven by an admin
customisation use case (App Builder reorders menu / hides pages / overrides
locale per tenant) SHALL add the endpoint when needed.

#### Scenario: Endpoint returns 404 today

- **WHEN** a request hits `/index.php/apps/openregister/api/manifest`
- **THEN** the response is HTTP 404 and the loader silently keeps the
  bundled manifest

#### Scenario: Follow-up change is tracked

- **WHEN** a reader looks up "OR backend manifest endpoint"
- **THEN** the answer points at the deferred follow-up task (`tasks.md`
  §7.1) and the App Builder driver

---

### Requirement: REQ-OR-MAN-010 Tier 3 and Tier 4 are explicitly deferred

The current change SHALL NOT replace OR's bespoke `NcAppNavigation` mount
with `CnAppNav` (Tier 3) and SHALL NOT swap `App.vue` for `CnAppRoot`
(Tier 4). The deferral is intentional: OR's `App.vue` orchestrates loading,
OR-availability checks, and sidebar provisioning that do not yet have a
clean `CnAppRoot` analogue. Tier 3 and Tier 4 each get their own follow-up
change.

#### Scenario: App.vue stays bespoke

- **WHEN** the change lands
- **THEN** `App.vue` still mounts `NcContent` + `NcAppNavigation` directly
  and does not consume `CnAppRoot`

#### Scenario: Follow-up tier changes are tracked

- **WHEN** a reader looks up "Tier 3 / Tier 4 for OR"
- **THEN** the answer points at `tasks.md` §7.3 (Tier 3) and §7.4 (Tier 4)

---

### Requirement: REQ-OR-MAN-011 Page-type "custom" share is acknowledged and tracked

The change SHALL acknowledge that 17 of OR's 30 routes (57%) map to
`type:"custom"` because the closed `type` enum has no `logs`, `settings`,
`chat`, or `files` built-ins today. This high custom share is the strongest
fleet-wide signal that the enum should grow upstream.

The change SHALL track this as a follow-up nextcloud-vue change
(`add-app-manifest-page-types`, not yet created) and SHALL NOT attempt to
work around the enum at the OR layer (no consumer-side enum extensions).

#### Scenario: Custom share is documented

- **WHEN** `docs/manifest.md` is read
- **THEN** the page-type mapping table from design.md is reproduced and
  the custom share is called out as the trigger for upstream enum work

#### Scenario: Upstream change is referenced

- **WHEN** a reader asks "why is this so much custom?"
- **THEN** the answer points at the page-type-enum follow-up in
  `tasks.md` §7.2 and at design.md Open Question 2
