# Tasks: Object-layer RBAC fail-closed hardening

## Phase 1 — Fail-closed object writes for anonymous (#1955)

- [x] In the object create/update authorization path (`ObjectService::checkSavePermissions`
      and/or `PermissionHandler`), when the resolved principal is anonymous
      (`IUserSession` has no user) AND no authorization rule explicitly grants the
      `public` group the requested write action, return/throw a 403 denial.
- [x] Preserve declared public-submission: a schema whose `authorization` grants
      `public` `create`/`update` continues to allow anonymous writes for that action.
- [x] Do NOT change authenticated-user write behaviour in this change (tracked
      separately in #1955) — scope the new denial to anonymous principals.

## Phase 2 — Fail-closed SQL match evaluation (#1953)

- [x] In `MagicRbacHandler::buildPropertyCondition`/`buildMatchConditions`, when a
      `match` property's dynamic value resolves to null, emit an impossible
      predicate (`1 = 0`) for that rule instead of dropping the condition.
- [x] Verify single-condition and multi-condition `match` rules now produce the
      same verdict on LIST as the PHP/find path does (fail-closed), and that
      rules whose variables DO resolve are unaffected (no new denials).

## Phase 3 — Tests

- [x] Unit test: anonymous object create/update on a no-rule schema → denied;
      on a schema with an explicit `public` create rule → allowed; authenticated
      user unaffected.
- [x] Shared RBAC parity test: for a schema with a multi-condition `match` rule on
      `$organisation`, a principal with null `$organisation` gets identical
      verdicts from LIST (`searchObjects`) and FIND (`find`) — both deny.
- [x] Run the OR unit suite for the touched handlers; ensure no regressions.

## Phase 4 — Runtime verification

- [x] Anonymous `POST /api/objects/{register}/{schema}` on a no-rule schema → 403
      (was 201); authenticated create still works; a `public`-create schema still
      accepts anonymous create.
- [x] Reproduce the #1953 setup (multi-condition `$organisation` match, anonymous
      caller): LIST and FIND now both deny (LIST no longer leaks the object).
- [x] Restore the instance + clean up fixtures after verification.

## Acceptance criteria
- Anonymous writes are denied by default and only allowed where a schema declares a public-write rule.
- SQL/list and PHP/find RBAC `match` evaluation agree (fail-closed) for null-resolved dynamic variables.
- No new denials for rules whose variables resolve; authenticated-user write behaviour unchanged.
