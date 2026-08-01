# Tasks — fix object read visibility gate

Phases 0 and 3 are the work. Phases 1–2 are ~15 lines and must not be written
before Phase 0 is answered.

## 0. Ground truth (blocking — do first)

- [ ] 0.1 Trace `PermissionHandler::hasPermission()` end to end: which actions
      exist, how owner / group / `public` are resolved, and whether `read` is
      honoured the same way `create` is.
- [ ] 0.2 **Explain the anomaly.** Setting `read: ['rbac-editors']` on schema 18
      flipped `rbac-editor` from 0 to 21 visible objects, even though the
      visibility gate asks for `create`. Identify what consults read rules on
      that path. Do not proceed until this is understood — the whole diagnosis
      rests on it.
- [ ] 0.3 Record the answer in this change's `design.md`.

## 1. Correctness fix

- [ ] 1.1 `lib/Service/Object/PermissionHandler.php`,
      `filterObjectsForPermissions()` (~line 911): `action: 'create'` →
      `action: 'read'`.
- [ ] 1.2 Audit the sibling call sites for the same mistake before touching
      them: `filterUuidsForPermissions()`, the single-object read path (see the
      isolation comment in `MagicMapper` ~5265), `RenderObject` ~1766.
- [ ] 1.3 Preserve fail-closed behaviour: an unresolvable authorization must
      still hide the object (`resolveReadGroupIds()` logs "fail-closed" ~1565).

## 2. `users` sentinel

- [ ] 2.1 `resolveReadGroupIds()` and `extractGroupIdsFromReadEntries()`:
      recognise `users` alongside the existing `public` / `admin` broadcast
      values.
- [ ] 2.2 `users` means **any authenticated caller, never anonymous**. The
      anonymous path (`groupId: 'public'`, ~516/538/647) must NOT match it —
      that distinction is the entire point of the value.
- [ ] 2.3 Document the three read values (`public`, `users`, named group) where
      the authorization block is described.

## 3. Verification matrix (shippability gate)

Each row asserted, not reasoned about:

- [ ] 3.1 anonymous + `read: ['public']` → visible
- [ ] 3.2 anonymous + `read: ['users']` → **not** visible
- [ ] 3.3 authenticated non-member + `read: ['users']` → visible at the OR layer,
      filtered to nothing by the consuming app's own permission check
- [ ] 3.4 authenticated + named group → visible only when in that group
- [ ] 3.5 admin → unchanged (bypass)
- [ ] 3.6 owner-only object → unchanged
- [ ] 3.7 openbuild e2e suite stays green (30+ tests currently passing)
- [ ] 3.8 at least one other app on a shared register re-run — OR is the
      foundation, so regressions surface elsewhere first

## 4. openbuild follow-through (separate PR, after 1–3 land)

- [ ] 4.1 Set `read: ['users']` on the `application` schema in
      `openbuild/lib/Settings/openbuild_register.json`.
      ⚠️ Register schema changes need a FORCED import to apply — a normal import
      advances the version WITHOUT applying the change.
- [ ] 4.2 Un-skip `versionRouting` 9.2 and `schema-access-scopes-rbac` (3), and
      add the viewer-gating assertion to `save-as-template`. Fixture groundwork
      is already in `openbuild/tests/e2e/support/appRoles.ts`; RBAC users and
      per-role sessions come from `globalSetup`.
- [ ] 4.3 Revert the experimental `read: ['rbac-editors']` left on schema 18 of
      the `ob-vue3-e2e` (:8099) instance during investigation.

## Evidence

Full measurement trail, including the raw numbers and the code references, is in
Conduction/openbuild#76.
