# Tasks — object read visibility

Phase 0 is complete and changed the plan. Phases 1–3 of the original task list
(swap `create`→`read`, add a `users` sentinel, prove a six-row matrix) are
**withdrawn**: the first is a change to dead code, the second duplicates the
existing `authenticated` pseudo-group, and the third was written to verify a
behavioural change that is no longer being made.

## 0. Ground truth — DONE

- [x] 0.1 Trace the live read path. It is
      `MagicSearchHandler:579` → `MagicRbacHandler::buildRbacConditionsSql(action: 'read')`.
- [x] 0.2 Explain the anomaly. The `create` gate never ran —
      `filterObjectsForPermissions()` has no production caller. The `read` rule
      was consumed by the SQL gate. See `design.md`.
- [x] 0.3 Record the answer in `design.md`, including the cross-handler contract
      table and the reasoning error that made this phase necessary.

## 1. openbuild fix (the actual unblock)

- [ ] 1.1 Add `"read": ["authenticated"]` to the authorization block of all six
      schemas in `openbuild/lib/Settings/openbuild_register.json`.
      Anonymous callers stay excluded — `authenticated` requires `$userId !== null`
      (`MagicRbacHandler.php:414`).
- [ ] 1.2 Re-import with **force**. A normal import advances the version WITHOUT
      applying the change.
- [ ] 1.3 Verify against the live instance, not by reading the JSON: a non-admin
      session lists the applications, and an anonymous request still does not.

## 2. openbuild test follow-through

- [ ] 2.1 Un-skip `versionRouting` 9.2 and the three `schema-access-scopes-rbac`
      scenarios; add the viewer-gating assertion omitted from `save-as-template`.
      Fixtures are already in `tests/e2e/support/appRoles.ts`; RBAC users and
      per-role sessions come from `globalSetup`.
- [ ] 2.2 Full openbuild e2e suite green.

## 3. OpenRegister cleanup (no behavioural change)

- [ ] 3.1 `filterObjectsForPermissions()` — remove it, or wire it up. Removing is
      preferred: the live gate is in SQL and a second, post-load object filter
      would duplicate it. If removed, delete
      `tests/Service/ObjectHandlersIntegrationTest.php:1429-1436` with it.
- [ ] 3.2 `docs/features/organisation-roles.md:670,673` — point Read and List at
      `MagicRbacHandler::buildRbacConditionsSql()`. This doc line is part of why
      the dead function looked live.
- [ ] 3.3 Post the correction to Conduction/openbuild#76, which currently carries
      the superseded diagnosis.

## 4. Deferred — investigate before touching

- [ ] 4.1 `resolveReadGroupIds()` treats a missing `read` key as broadcast; the
      list path treats it as owner-only. Establish whether `getReadableByUsers()`
      produces a wrong user-visible outcome as a result. Do not change it on the
      asymmetry alone — the same reasoning shortcut is what produced the first
      version of this proposal.

## Withdrawn

- ~~`action: 'create'` → `'read'` in `filterObjectsForPermissions()`~~ — dead code;
  superseded by 3.1.
- ~~`filterUuidsForPermissions()` gates on `delete`~~ — correct; its only caller
  is `deleteObjects()`.
- ~~Add a `users` sentinel~~ — `authenticated` already exists and is specified in
  `openspec/specs/rbac-scopes/spec.md:178`.
- ~~Six-row verification matrix~~ — written to gate a behavioural change that is
  no longer proposed. Task 1.3 carries the verification that is still owed.

## Evidence

Measurement trail in Conduction/openbuild#76; corrected reasoning in `design.md`.
