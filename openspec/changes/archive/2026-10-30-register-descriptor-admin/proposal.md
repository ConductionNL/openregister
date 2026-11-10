---
kind: code
issue: https://github.com/ConductionNL/openregister/issues/2903
---

## Why

**18 of the 21 fleet apps ship a register descriptor and can only ever import it during `occ upgrade`** — and once `installed_version` matches `info.xml`, `occ upgrade` reports "No upgrade required" and the repair step never runs again. There is no in-product way to re-run one and no way to see whether one succeeded.

The failure is silent by construction. `ImportFlowRegister`'s own docblock states it *"Never throws — a failure logs a warning and leaves the instance otherwise healthy."* An app whose descriptor never landed looks installed and works for everything except the paths that need the register.

This was found the expensive way. On a rebuilt dev instance the `flows` register was simply absent; `flow-schedule.spec.ts` and `federated-config.spec.ts` both die in `beforeAll` on `registers slug=flows`, and nothing anywhere says the seed never landed. Establishing that took an account listing, a register dump and a read of the repair step — to learn a fact the product could have stated in one line.

ADR-005 settled *who* seeds OR's own registers (idempotent Repair steps) and required in Rule 2 that they be safe to re-run. It never gave anyone a way to re-run them. This change supplies the missing trigger and the missing visibility; it does not move seeding out of Repair steps.

## What Changes

- A new **Register descriptors** panel in OpenRegister's admin settings listing every app that declares a register descriptor — **including the apps whose register is absent**, which is the entire point.
- Each row reports: owning app, register slug, **present or absent**, the installed descriptor version, and the version the app currently ships. Absent and behind are distinct states and are rendered distinctly.
- A per-row action re-imports the descriptor through `ConfigurationService::importFromApp(..., force: true)`. The `force` flag is required, not optional: without it `ImportHandler` short-circuits on `version_compare($data['version'], $existingVersion, '<=')` and the button would be a silent no-op in exactly the case an admin presses it.
- The action **reports its outcome** — imported, already current, or failed with the reason. A repair step may swallow its own failure at boot; a button an admin just pressed may not.
- A read-only API (`GET /api/register-descriptors`) backing the panel, and a `POST /api/register-descriptors/{appId}/{slug}/import` for the action.
- An `occ openregister:descriptors:list` command exposing the same inventory, so the diagnosis is available on a box with no browser.

Not in scope: per-app admin buttons in the other 17 apps. An app wanting one links to this panel.

## Capabilities

### New Capabilities
- `register-descriptor-admin`: enumerate every app-declared register descriptor with its installed-vs-shipped state, and re-import one on demand with a reported outcome.

### Modified Capabilities

None. The panel consumes `register-resolver-service` and `settings-management` as they stand; neither's requirements change.

## Impact

**Code**
- New `lib/Service/RegisterDescriptorService.php` — enumeration and forced re-import.
- New `lib/Controller/RegisterDescriptorController.php` — the two endpoints.
- New `lib/Command/DescriptorListCommand.php` — the occ inventory.
- New `src/views/settings/RegisterDescriptors.vue` plus its store — the panel.
- `appinfo/routes.php`, `appinfo/info.xml` (command registration).

**Depends on, unchanged**
- `ConfigurationService::importFromApp(string $appId, array $data, string $version, bool $force = false)` — already carries the `force` parameter this change needs; no signature change.
- `RegisterResolverService` — already reads each app's `<context>_register` / `<context>_schema` `IAppConfig` keys (see `ResolverListCommand`).

**Architecture**
- ADR-005 is complemented, not contradicted: seeding stays in Repair steps; this adds an admin-triggered path to the same idempotent import plus the visibility ADR-005's own Consequences section identifies as its cost.

**Risk**
- A forced re-import updates the base descriptor. An extended schema **extends its base**, so it is impervious to the base moving and no diff-and-confirm flow is needed. This holds only while the extension relationship is by reference; an extension materialised as a copy at import time would silently revert, so the design pins that with a test rather than assuming it.
