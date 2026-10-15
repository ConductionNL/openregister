## 1. Enumeration

- [x] 1.1 Add `RegisterDescriptorService::inventory()` returning one row per app that ships a register descriptor — app id, register slug, state (`absent` / `behind` / `current`), installed version, shipped version. Walk installed apps for the shipped descriptor file; read the slug the descriptor claims, and resolve presence by that slug. Do NOT enumerate from resolver keys or configuration rows — an app whose import never ran has neither, and that row is the point.

  Acceptance criteria:
  - An app with a descriptor and no matching register yields a row with state `absent` and no installed version.
  - An app whose register matches the shipped version yields `current`.
  - An app whose register is older than the shipped descriptor yields `behind` with both versions.
  - An app shipping no descriptor is omitted, not listed as absent.

- [x] 1.2 Pin the descriptor-discovery rule against the fleet: assert the inventory finds a descriptor for every app known to ship one. A discovery rule that is too narrow silently omits apps, reproducing the invisibility this change exists to fix — so a missed app must fail the test, not shrink the list.

## 2. Forced re-import

- [x] 2.1 Add `RegisterDescriptorService::reimport(string $appId)` calling `ConfigurationService::importFromApp(..., force: true)` and returning `imported` / `unchanged` / `failed` with a reason on failure. `force` is mandatory — `ImportHandler` short-circuits on `version_compare(..., '<=')` when it is false, which is exactly the case an administrator presses the button in.

  Acceptance criteria:
  - Re-importing a descriptor whose shipped version equals the installed version reports `imported`, and the descriptor is written.
  - Re-importing an absent register creates it; a subsequent inventory reports `current`.
  - A failing import reports `failed` with the reason, and does not leave the inventory reporting `current`.

- [x] 2.2 Test that a schema extending an app's base schema survives a forced re-import of that app's descriptor — the extension still exists, its own properties are unchanged, and it still resolves against the base. `Schema::getAllOf()` holds ids/uuids/slugs, so extension is by reference; this test is what stops that becoming an assumption. An extension materialised as a copy would revert somebody's customisation through the button offered as a repair.

## 3. Surfaces

- [x] 3.1 Add `RegisterDescriptorController` with `GET /api/register-descriptors` (inventory) and `POST /api/register-descriptors/{appId}/{slug}/import` (forced re-import), wired in `appinfo/routes.php`. Both administrator-only.

  Acceptance criteria:
  - A signed-in non-administrator is refused on both endpoints, and no descriptor is written on the refused import.
  - The import response carries the outcome value and, on failure, the reason.

- [x] 3.2 Add `occ openregister:descriptors:list` printing the same rows — state and both versions per row. It formats `inventory()`; it does not reimplement it, so the two surfaces cannot drift. The condition being diagnosed often coincides with an unreachable admin UI.

- [x] 3.3 Add the **Register descriptors** panel to admin settings, rendering `absent` and `behind` rows distinctly from `current` and offering the re-import action per row. Surface the reported outcome — a button that reports nothing is indistinguishable from one that did nothing.

  Acceptance criteria:
  - Absent rows are visible without filtering; the panel does not default to hiding healthy-or-absent alike.
  - The outcome of a re-import is shown to the administrator who triggered it.
  - Strings are translatable, CSS variables only, WCAG AA.

## 4. Close the loop

- [x] 4.1 Use the new command to import the `flows` register on an instance missing it, then run `flow-schedule.spec.ts` and `federated-config.spec.ts` to green. These two specs die in `beforeAll` on `registers slug=flows` today and are the concrete failure that motivated this change; if they still cannot run, the capability has not delivered what it claimed.

- [x] 4.2 Document the panel in the admin docs and reference ADR-005 — seeding stays in Repair steps; this is the trigger and the visibility that decision left out.
