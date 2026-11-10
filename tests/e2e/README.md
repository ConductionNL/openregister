<!--
SPDX-FileCopyrightText: 2026 Open Register Contributors
SPDX-License-Identifier: EUPL-1.2
-->

# OpenRegister end-to-end (Playwright) specs

These specs drive a **live** Nextcloud + OpenRegister. They are **not**
wired as a required PR CI job: the shared `ConductionNL/.github` quality
workflow has no Nextcloud container, so a CI-required Playwright gate
would be permanently red. They run against the dev container (or any
reachable NC) as documented manual / opt-in entry points.

Point at a running instance with `NEXTCLOUD_URL` (default
`http://localhost:8080`). `globalSetup` logs in once (admin/admin;
override with `NC_ADMIN_USER` / `NC_ADMIN_PASS`) and persists the session
to `tests/e2e/.auth/admin.json`.

```bash
npm run test:e2e:install          # one-time: install the chromium browser
NEXTCLOUD_URL=http://localhost:8080 npm run test:e2e
```

## Specs (UI regression project — `chromium`)

| Spec | What it asserts | Layer |
| --- | --- | --- |
| `manifest-shell.spec.ts` | CnAppRoot manifest shell mounts; registry dispatch for index/detail/dashboard routes; nav sections + order; no deprecation warning | UI (manifest) |
| `ui-navigation.spec.ts` | All main nav routes load the app-content shell | UI (nav) |
| `core-crud.spec.ts` | Register/schema/object create + edit through the real UI modals | UI (CRUD) |
| `leaf-screenshots.spec.ts` | One PNG per provider tab — via the **isolated** `IntegrationsView` | UI (screenshots) |
| **`integration-mount.spec.ts`** | **Each advertised provider tab MOUNTS a component on the real `ObjectDetails.vue` page** | **UI (mount)** |
| `docs-screenshots.spec.ts` | Journeydoc tutorial screenshots (`--project docs-capture`) | UI (screenshots) |

The `spec-coverage/` subdir holds additional UI specs (entity-management
modals, files-sidebar tabs, platform-administration modals, saved-search views,
register i18n, data import/export).

## API-direct specs → Newman (`api-direct/`, excluded)

Specs that assert HTTP/JSON contracts via Playwright's `request` fixture (no
browser) have been relocated to `tests/e2e/api-direct/` and are **excluded from
the `chromium` regression project** (`testIgnore: ['**/api-direct/**']`). Per
ADR-020 / gate-19 the API contracts they cover live in the Newman collections
under `tests/integration/` + `tests/newman/`. See `api-direct/README.md` for the
per-spec → Newman-collection mapping.

## Integration mount-check — `npm run test:e2e:integrations` (Phase K / K3)

```bash
NEXTCLOUD_URL=http://localhost:8080 npm run test:e2e:integrations
```

This is the **regression guard for the Phase-A "bespoke UI is dead code"
wiring bug** (ADR-019). During the integration-leaves rollout the OCS API
was green and the bespoke `Cn<X>Tab` components were present in the nc-vue
bundle, yet none rendered, because `ObjectDetails.vue`'s "Integrations"
tab never dispatched `<component :is="provider.tab || CnIntegrationTab">`
(and a duplicate `<script setup>` block had dropped the Options-API
`setup()` that drains the registry, leaving `integrationProviders`
empty). The API-only specs above could not catch this — they never open
an object detail page in a browser.

`integration-mount.spec.ts` navigates to a **real object detail page**
(the `ObjectDetails.vue` surface, *not* the isolated `IntegrationsView`
used by `leaf-screenshots.spec.ts`), opens the Integrations tab, walks
every advertised provider's inner sub-tab, and asserts each one mounts a
non-empty component.

### Requirements (why this isn't a CI job)

- A running NC + OpenRegister with the integration registry wired
  (`Application.php` `registerBuiltinIntegrationProviders` +
  `bootBuiltinIntegrationProviders`, nc-vue `registerBuiltinIntegrations`
  + `registerLeafIntegrations` at bootstrap).
- At least one **saved object** so `ObjectDetails.vue`'s
  `relationContext` is non-null (the Integrations tab is gated on it).
  Seed the `integration-verification` sandbox register, or any register
  with one object.

The spec **self-skips** when the registry isn't wired or no object is
reachable, so a casual `npx playwright test` on a half-set-up box never
hard-fails.
