# Tasks: Register & Schema read accessibility under app-group restriction

## Phase 1 — Make the register read surface public

- [x] Add `#[PublicPage]` to `RegistersController::index` (keep existing
      `#[NoAdminRequired]` + `#[NoCSRFRequired]`).
- [x] Add `#[PublicPage]` to `RegistersController::show`.
- [x] Confirm `SchemasController::index/show` and `ObjectsController::index/show`
      already carry `#[PublicPage]` (no change expected — assert in a test).

## Phase 2 — "Logged-in unless published" read guard

- [x] Add a private helper (shared shape across both controllers, e.g.
      `resolveReadVisibility()`): resolves the current `IUserSession` user;
      returns an enum/bool indicating `authenticated` vs `anonymous`.
- [x] `RegistersController::index` + `SchemasController::index`: when the caller
      is anonymous, constrain the result set to resources where `published` is
      non-null AND `depublished` is null (published-only). Authenticated callers
      get the normal RBAC/multitenancy-scoped set.
- [x] `RegistersController::show` + `SchemasController::show`: when the caller is
      anonymous and the resource is not published, return `401`; otherwise serve.
- [x] Ensure the guard reads `published`/`depublished` from the entity, not from
      request params (no client-controlled bypass).

## Phase 3 — Verification

- [x] Unit/integration test matrix per endpoint (register & schema, index & show):
      authenticated-in-group, authenticated-out-of-group, anonymous+published,
      anonymous+unpublished.
- [x] Runtime check: with `occ app:enable openregister --groups <group>` and a
      user outside the group — `GET /api/registers` and `GET /api/schemas` return
      200 (previously register list 412); object reads unaffected; create/update/
      delete still 403/412.
- [x] Runtime check: anonymous `GET /api/registers` returns only published
      registers; anonymous `GET /api/registers/{unpublished}` returns 401.
- [x] Restore the instance (`occ app:enable openregister`) after testing.

## Phase 4 — Documentation

- [x] Add a "Restricting OpenRegister to a user group" note to the auth/access
      docs: group restriction limits **management** to the group; register/schema/
      object **reads** stay reachable (anonymous only for published resources,
      logged-in users subject to RBAC). Cross-link the write-gating behaviour
      (PR #1949).
