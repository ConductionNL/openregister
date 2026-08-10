---
status: done
---

# OpenRegister app-manifest capability

## Purpose

Define OpenRegister's adoption of the JSON-driven app-manifest pattern published
by `@conduction/nextcloud-vue` (ADR-024). OR ships a single `src/manifest.json`
that declares its full shell — menu items, route → page mapping, and per-page
configuration — and mounts that manifest through `CnAppRoot` + `CnPageRenderer`
so the runtime renders the entire app from data instead of from bespoke
`App.vue`/`router/index.js` boilerplate.

OR is the fleet's foundation app and therefore the **canonical Tier-4 adopter**:
its shape (manifest + registry + CnAppRoot + CnAppNav + CnPageRenderer) is the
reference every other Conduction app mirrors.

Adopted via the archived change
`2026-05-28-openregister-manifest-shell-swap` (33/33 tasks complete) and the
preceding `2026-05-27-superseded-openregister-adopt-app-manifest` proposal that
designed it.

## Requirements

### Requirement: REQ-OR-MAN-001 Manifest file exists at canonical location @e2e exclude build-time static-file assertion (manifest exists/loadable, schema referenced not duplicated) — covered by manifest validator (tests/validate-manifest.js)

OpenRegister SHALL ship a `src/manifest.json` validated against the v2 schema
published by `@conduction/nextcloud-vue` per ADR-024 §1-2. The file SHALL set
`$schema` to the published GitHub raw URL of
`nextcloud-vue/src/schemas/app-manifest-v2.schema.json`. The file MUST NOT
fork or duplicate the schema.

#### Scenario: Manifest exists and is loadable

- **WHEN** the OpenRegister Vue bundle is built
- **THEN** `src/manifest.json` is present, parses as valid JSON, and matches
  the canonical schema (passes the build-time `npm run check:manifest` gate)

#### Scenario: Schema is referenced, not duplicated

- **WHEN** a reviewer inspects `src/manifest.json`
- **THEN** the `$schema` field points at the canonical v2 schema URL and the
  OR repo contains no copy of that schema file (a vendored copy is allowed
  ONLY under `tests/schemas/` as a CI-portability fallback while the package
  catches up)

---

### Requirement: REQ-OR-MAN-002 Manifest declares zero Conduction-app dependencies @e2e exclude build-time static manifest/loader assertion (empty deps, loader skips dep check) — covered by manifest validator + Jest loader unit

The manifest's `dependencies` field SHALL be an empty array `[]`. OpenRegister
is the platform foundation; it has no upstream Conduction-app dependencies.

#### Scenario: Foundation declares no dependencies

- **WHEN** `src/manifest.json` is loaded
- **THEN** `manifest.dependencies` is the empty array `[]`

#### Scenario: Loader skips dependency check on empty deps

- **WHEN** `CnAppRoot` runs and reads `dependencies: []` (passed via
  `:requires-apps="[]"` in `App.vue`)
- **THEN** the `dependency-check` phase is a no-op and the shell enters the
  `loading` → `shell` transition without rendering `CnDependencyMissing`

---

### Requirement: REQ-OR-MAN-003 Manifest declares one entry per route

The manifest's `pages[]` SHALL contain exactly one entry per route OR exposes
(currently 31, excluding the `*` catch-all). Each entry SHALL set `id` (= route
name, matched against `$route.name` by `CnPageRenderer`), `route` (= path
pattern), `type`, and `title` (i18n key).

OR's foundation entities (registers / schemas / sources / organisations /
applications / endpoints / entities) and the generic objects/tables/audit/
search-trail/webhook surfaces are **not register-stored objects** — they are
served by dedicated OR controllers/stores, so the library's built-in
`index`/`detail`/`dashboard` renderers (which resolve via `useObjectStore`
against a register+schema slug) cannot drive them. Consequently every OR page
declares `type:"custom"` with a `component` string resolved against the
kind-tagged registry. This is the documented D3 fallback in the shell-swap
design; each page carries an inline `_note` documenting why a built-in was
not feasible.

#### Scenario: Page count matches the manifest validator

@e2e exclude build-time validator assertion (page count == menu destinations, unique ids) — covered by tests/validate-manifest.js

- **WHEN** the build runs `check:manifest`
- **THEN** `manifest.pages[].length` equals the number of declared menu
  destinations (currently 31) and every `id` is unique

#### Scenario: Every page resolves to a registry component

- **WHEN** the user navigates to any declared route
- **THEN** `CnPageRenderer` matches `$route.name === page.id`, reads
  `page.type === "custom"`, and resolves `page.component` against the v2
  kind-tagged `registry` prop's `kind:"page"` entries (ADR-036)

#### Scenario: Titles are i18n keys

@e2e exclude build-time static assertion (page titles are i18n keys) — covered by manifest validator + l10n unit

- **WHEN** an entry's `title` is read
- **THEN** the value is a translation key resolved via the app's `t()`
  function in `l10n/{en,nl}.js`

---

### Requirement: REQ-OR-MAN-004 Manifest declares menu split into main and settings sections

The manifest's `menu[]` SHALL declare top-level navigation entries with two
`section` values: `"main"` for primary navigation and `"settings"` for the
configuration / log cluster. Each menu entry SHALL set `id`, `label` (i18n
key), `icon`, `route`, `order`, and `section`.

#### Scenario: Both sections are populated

- **WHEN** the manifest is loaded
- **THEN** `manifest.menu[]` contains entries with `section: "main"` and
  entries with `section: "settings"`, with no other section values

#### Scenario: Menu order is monotonic per section

- **WHEN** entries within a section are sorted by `order`
- **THEN** the visible order in `CnAppNav` matches the manifest order exactly

#### Scenario: Menu labels are i18n keys

@e2e exclude build-time static assertion (menu labels are i18n keys) — covered by manifest validator + l10n unit

- **WHEN** a menu entry's `label` is read
- **THEN** the value resolves via the app's `t()` function

---

### Requirement: REQ-OR-MAN-005 Bootstrap mounts CnAppRoot with the manifest

`src/main.js` SHALL `import bundledManifest from './manifest.json'` and
`import registry from './registry.js'`, build the vue-router config via
`routesFromManifest(bundledManifest)`, and mount `App.vue` (the thin
`CnAppRoot` wrapper) passing `manifest`, `registry`, and `pageTypes` as props.

`App.vue` SHALL render `<CnAppRoot app-id="openregister" :manifest="manifest"
:registry="registry" :page-types="pageTypes" :requires-apps="[]"
:translate="translateForApp">` with `#menu` / `#sidebar` / `#footer` slot
overrides for `MainMenu`, `SideBars` + `CnObjectSidebar`, and the global
`Modals`/`Dialogs` hosts.

#### Scenario: Router is built from the manifest

- **WHEN** the bundle initialises
- **THEN** every `manifest.pages[]` entry becomes a vue-router route with
  `name === page.id`, `path === page.route`, `component === CnPageRenderer`,
  and `props: true` for paths containing a `:` parameter; a catch-all
  `{ path: '*', redirect: '/' }` is appended

#### Scenario: CnAppRoot mounts the shell

- **WHEN** the OR Vue app mounts
- **THEN** `CnAppRoot` runs its loading → shell phases and renders
  `<router-view />`; the `#menu` slot renders OR's `MainMenu` (which embeds
  `CnAppNav` with the active-organisation switcher in `#primary-action`)

---

### Requirement: REQ-OR-MAN-006 CnPageRenderer dispatches every route

`CnPageRenderer` SHALL be the single `component` for every route in the
manifest-driven router (cloned via `RoutePageRenderer = { ...CnPageRenderer }`
to keep the lib's barrel export extensible for Vue 2's `_Ctor` cache). For
each route, the renderer matches `$route.name === page.id` and resolves
`page.component` against the v2 kind-tagged `registry` prop.

#### Scenario: Index-style routes dispatch via custom registry

- **WHEN** the user navigates to `/registers` (or any list route)
- **THEN** `CnPageRenderer` matches `id: "registers"` and resolves
  `component: "RegistersIndex"` against the `registry` prop's
  `kind:"page"` entries, rendering `RegistersIndex` inside the shell

#### Scenario: Detail-style routes dispatch via custom registry

- **WHEN** the user navigates to `/registers/abc-123`
- **THEN** `CnPageRenderer` matches `id: "registerDetail"`, props `:id` from
  the URL, and resolves `component: "RegisterDetail"` from the registry

#### Scenario: Dashboard dispatches via custom registry

- **WHEN** the user navigates to `/`
- **THEN** `CnPageRenderer` matches `id: "dashboard"` and resolves
  `component: "Dashboard"`, rendering the OR dashboard with all its
  KPI / chart widgets

---

### Requirement: REQ-OR-MAN-007 Build gate validates the manifest @e2e exclude build-time CI gate assertion (validator pass/fail, composite-check wiring) — covered by tests/validate-manifest.js + CI

The OR `package.json` SHALL declare a `check:manifest` script that runs
`tests/validate-manifest.js` against `src/manifest.json`. The script SHALL be
invoked from CI (via `check:specs` → `.github/workflows/spec-validation.yml`)
and SHALL fail the job on schema errors.

#### Scenario: Valid manifest passes the gate

- **WHEN** CI runs `npm run check:manifest` on a valid `src/manifest.json`
- **THEN** the script exits 0 and the job continues

#### Scenario: Invalid manifest fails the gate

- **WHEN** CI runs `npm run check:manifest` on a manifest with a schema
  violation (missing required field, unknown `type`, duplicate `id`, etc.)
- **THEN** the script exits non-zero, prints the validation error path
  inside the JSON, and the job fails

#### Scenario: Gate is wired into the composite check

- **WHEN** `npm run check:specs` is invoked
- **THEN** `check:manifest` runs as part of the composite

---

### Requirement: REQ-OR-MAN-008 Manifest version reflects the adoption tier @e2e exclude build-time static version assertion (tier-4 == 1.0.0, validator enforces field) — covered by manifest validator

The manifest's top-level `version` SHALL be a semver string declaring the
adoption tier. Tier-4 / full-shell adoption SHALL be expressed as `"1.0.0"`
(no longer sub-1.0.0 — the original Tier-1/Tier-2 numbering in the superseded
proposal is superseded by the shell-swap shipping the full shell at once).

#### Scenario: Tier-4 ships at 1.0.0

- **WHEN** the manifest is loaded
- **THEN** `manifest.version` is `"1.0.0"`

#### Scenario: Validator enforces the field

- **WHEN** the validator runs (Ajv against v2 schema, with structuralLint as
  a no-Ajv fallback)
- **THEN** an absent or empty-string `version` fails the gate

---

### Requirement: REQ-OR-MAN-009 Backend `/api/manifest` endpoint is deferred @e2e exclude API-contract assertion (endpoint returns 404, loader falls back to bundled manifest) — covered by Newman

**SUPERSEDED by REQ-OR-MAN-012.** The original deferral below no longer
describes the shipped code: the endpoint exists, is routed, and is exercised.
This heading is retained so existing references resolve; the normative statement
is REQ-OR-MAN-012.

The deferral read: OpenRegister SHALL NOT implement `GET
/index.php/apps/openregister/api/manifest`; `useAppManifest`'s silent fallback on
404 makes absence non-regressive, and a follow-up change driven by a real
admin-customisation use case SHALL add the endpoint when needed. That follow-up
landed — the driving use case was per-user `runtime.user` context for host apps.

#### Scenario: The bundled-manifest fallback still holds for unknown apps

- **WHEN** a request hits `/index.php/apps/openregister/api/manifest/{appId}`
  for an app that ships no bundled manifest
- **THEN** the response is HTTP 404 and the loader silently keeps the
  bundled manifest

---

### Requirement: REQ-OR-MAN-012 Backend manifest endpoint serves a host app's bundled manifest @e2e exclude API-contract assertion (public endpoint, appId validation, 404/500 mapping, ETag/304) — covered by Newman

OpenRegister SHALL route `GET /index.php/apps/openregister/api/manifest/{appId}`
to `ManifestController::index()`. The endpoint SHALL be a public page with no
CSRF requirement and no admin requirement, so an unauthenticated client can load
a manifest and receive `runtime.user = null` — nc-vue filters public pages on
that null signal. `appId` SHALL be validated against `^[a-z0-9_-]+$` (case
insensitive) before any path resolution, and SHALL yield HTTP 400 otherwise. The
controller SHALL load the named app's bundled `manifest.json` through the
Nextcloud app manager, return HTTP 404 when it is absent or unreadable, and
return HTTP 500 with a generic body — logging the detail server-side — when
enrichment throws. On success it SHALL return the enriched manifest with an
`ETag` over the final per-user payload and `Cache-Control: private, no-cache`, so
a matching `If-None-Match` becomes a 304 while enrichment still runs on every
request.

#### Scenario: Malformed app id is rejected before path resolution

- **WHEN** `GET /api/manifest/../../etc` is requested
- **THEN** the response MUST be HTTP 400 with an `Invalid app ID.` body
- **AND** no manifest path MUST be resolved

#### Scenario: Unknown app yields 404

- **WHEN** the named app ships no readable bundled manifest
- **THEN** the response MUST be HTTP 404

#### Scenario: Warm reload revalidates rather than re-transfers

- **GIVEN** a previous response carried an `ETag`
- **WHEN** the same user re-requests with a matching `If-None-Match`
- **THEN** the response MUST be HTTP 304
- **AND** the `Cache-Control` header MUST be `private, no-cache`

#### Scenario: Enrichment failure does not leak internals

- **GIVEN** enrichment throws
- **WHEN** the controller handles it
- **THEN** the response MUST be HTTP 500 with a generic `Internal server error.`
  body and the detail MUST be logged server-side only

---

### Requirement: REQ-OR-MAN-013 Manifest enrichment injects an allowlisted `runtime.user` block @e2e exclude backend enrichment pipeline (schema-slug validation, anonymous/no-profile shapes, field allowlist, calculation overlay) — covered by PHPUnit

`ManifestService::getEnrichedManifest()` SHALL return the manifest unchanged when
it declares no `currentUserSchema`. When it does, the slug SHALL be validated as
a string of at most 128 characters matching `^[A-Za-z0-9_\-]{1,128}$` BEFORE any
lookup uses it, and a failing slug SHALL fail closed to `runtime.user = null`
with a warning — a compromised manifest MUST NOT be able to steer profile or
calculation lookup at an arbitrary schema. An anonymous request SHALL yield
`runtime.user = null`. An authenticated user with no profile object for that
schema SHALL yield `runtime.user = { id, roles: ["learner"] }`.

Profile lookup SHALL run through `ObjectService::findAll()` with RBAC and
multi-tenancy left ON, narrowed by `(schema, ncUserId)`; the narrowing filter is
NOT a substitute for the tenant scope, because the same Nextcloud UID may exist
in more than one tenant.

When a profile is found, `runtime.user` SHALL be built from an ALLOWLIST rather
than from the raw profile payload: the fields named by the schema's
`x-openregister-manifest-user-fields` configuration plus the non-materialised
fields named in the schema's `x-openregister-calculations` map, with `id` always
set to the Nextcloud user ID. Fields outside that allowlist MUST NOT be
surfaced. Non-materialised calculations SHALL be evaluated at read time against
the profile data plus an injected `@self` block (`id`, `uuid`, `register`,
`schema`, `owner`, `created`, `updated`) and overlaid onto the block;
materialised calculations MUST NOT be re-evaluated, since their values are
already stored. A calculation that raises an evaluation error SHALL be logged
and skipped, and MUST NOT fail the request.

#### Scenario: No currentUserSchema leaves the manifest untouched

- **GIVEN** a manifest with no `currentUserSchema` key
- **WHEN** it is enriched
- **THEN** the returned manifest MUST be identical to the input

#### Scenario: Invalid schema slug fails closed

- **GIVEN** a manifest whose `currentUserSchema` is not a string, is longer than
  128 characters, or contains characters outside `[A-Za-z0-9_-]`
- **WHEN** it is enriched
- **THEN** `runtime.user` MUST be `null` and a warning MUST be logged
- **AND** no schema or profile lookup MUST be performed

#### Scenario: Anonymous request yields a null user

- **GIVEN** no user in the session
- **WHEN** a manifest declaring `currentUserSchema` is enriched
- **THEN** `runtime.user` MUST be `null`

#### Scenario: Authenticated user without a profile gets the minimal fallback

- **GIVEN** an authenticated user with no profile object for the declared schema
- **WHEN** the manifest is enriched
- **THEN** `runtime.user` MUST be `{ id: <uid>, roles: ["learner"] }`

#### Scenario: Fields outside the allowlist are not surfaced

- **GIVEN** a profile object carrying a field that is named neither by
  `x-openregister-manifest-user-fields` nor by the calculation map
- **WHEN** the manifest is enriched
- **THEN** that field MUST NOT appear in `runtime.user`

#### Scenario: A failing calculation is skipped, not fatal

- **GIVEN** one non-materialised calculation whose expression raises
- **WHEN** the manifest is enriched
- **THEN** the failure MUST be logged and the remaining calculations MUST still
  be applied

---

### Requirement: REQ-OR-MAN-010 Tier-4 shell is adopted @e2e exclude build-time source-inspection assertion (App.vue is CnAppRoot wrapper, legacy router file removed) — covered by static code inspection + Jest. Runtime shell mount is covered by manifest-shell.spec.ts (REQ-OR-MAN-005)

OR `App.vue` SHALL be the `CnAppRoot` wrapper described in REQ-OR-MAN-005,
NOT a bespoke `NcContent` + `NcAppNavigation` mount. The legacy `src/router/
index.js` SHALL be deleted; all route construction goes through
`routesFromManifest` in `main.js`.

This requirement replaces the superseded "Tier 3/Tier 4 are deferred"
posture in the original proposal — the shell-swap (archive change
`2026-05-28-openregister-manifest-shell-swap`) adopted both at once.

#### Scenario: App.vue is the CnAppRoot wrapper

- **WHEN** a reviewer inspects `src/App.vue`
- **THEN** the root template is `<CnAppRoot ...>` with `#menu` / `#sidebar`
  / `#footer` slot overrides; no direct `NcContent` / `NcAppNavigation`
  mount is present

#### Scenario: Legacy router file is gone

- **WHEN** a reviewer searches `src/`
- **THEN** `src/router/index.js` does not exist; only `main.js` imports the
  router config

---

### Requirement: REQ-OR-MAN-011 Custom-component registry uses the v2 kind-tagged shape

The `registry` prop passed to `CnAppRoot` SHALL be a v2 kind-tagged map per
ADR-036. Page components MUST be wrapped as `{ kind: "page", component }`
entries; OR uses a `page(component)` helper in `src/registry.js`. The
deprecated `customComponents` prop SHALL NOT be passed to `CnAppRoot`.

#### Scenario: Registry uses kind:"page" wrapping

@e2e exclude build-time source-inspection assertion (registry entries use kind:"page" via page() helper) — covered by static code inspection + Jest

- **WHEN** a reviewer inspects `src/registry.js`
- **THEN** each entry is `{ kind: "page", component: <imported component> }`,
  produced via the `page()` helper

#### Scenario: No customComponents prop

@e2e exclude build-time source-inspection assertion (no customComponents prop passed to CnAppRoot) — covered by static code inspection. Runtime no-deprecation-warning is covered by manifest-shell.spec.ts

- **WHEN** a reviewer inspects `src/App.vue` / `src/main.js`
- **THEN** neither file passes `customComponents` to `CnAppRoot`; only
  `registry` is bound

#### Scenario: No deprecation warning at runtime

- **WHEN** the OR shell mounts in a browser
- **THEN** the lib emits no `CnAppRoot: customComponents prop is deprecated`
  console warning
