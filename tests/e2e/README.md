<!--
SPDX-FileCopyrightText: 2026 Open Register Contributors
SPDX-License-Identifier: AGPL-3.0-or-later
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

## Specs

| Spec | What it asserts | Layer |
| --- | --- | --- |
| `api-smoke.spec.ts` | OR REST API smoke | API |
| `integration-registry.spec.ts` | Every provider is advertised via OCS capabilities with the documented shape; sub-resource endpoints never 5xx | API |
| `leaf-verification.spec.ts` | Per-leaf probe report (status, shape, latency) → `leaf-verification.json` | API |
| `leaf-screenshots.spec.ts` | One PNG per provider tab — via the **isolated** `IntegrationsView` | UI (screenshots) |
| **`integration-mount.spec.ts`** | **Each advertised provider tab MOUNTS a component on the real `ObjectDetails.vue` page** | **UI (mount)** |
| `docs-screenshots.spec.ts` | Journeydoc tutorial screenshots (`--project docs-capture`) | UI (screenshots) |

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
