---
kind: code
---

# Object-layer RBAC fail-closed hardening

## Problem

Two object-layer authorization defects were found by a deep security review and **confirmed at runtime** (2026-05-27) on a live instance:

1. **Object writes are default-open through to anonymous callers (#1955).** `PermissionHandler::hasGroupPermission()` returns `true` for everyone when a schema has no `authorization` block (or no entry for the requested action), and `ObjectService::checkSavePermissions()` routes create/update through it; unauthenticated requests fall through to the `public` group. Verified: an **anonymous** `POST /api/objects/decidesk/meeting` returns **201** (object created), as does a non-admin user. The register/schema *model* CRUD was hardened to default-secure in #1949 (`checkSchemaManagePermission`), but object writes were left default-open.

2. **RBAC `match` evaluation fails OPEN on the SQL/list path (#1953).** The PHP/find evaluator (`ConditionMatcher`) denies when a dynamic variable (e.g. `$organisation`) resolves to `null` (fail-closed), but the SQL/list evaluator (`MagicRbacHandler::buildPropertyCondition` → `buildMatchConditions`) returns `null` for the unresolved predicate and **drops it from the AND**, degrading a multi-condition `match` rule to its surviving static predicate. Verified: for an anonymous principal (the genuine null-`$organisation` case — authenticated users auto-receive the Default Organisation), a `public` read rule `{name: …, organisation: "$organisation"}` made `GET /api/objects/{r}/{s}` (LIST) return an object that `GET /api/objects/{r}/{s}/{uuid}` (FIND) denied — a cross-tenant/visibility bypass on the list endpoint. (Single-predicate `match` rules fail closed — the bypass only manifests for multi-condition blocks.)

Both are direct ADR-005 violations (RBAC must be enforced consistently across access methods; authorization must fail closed).

## Context

- `lib/Service/Object/PermissionHandler.php` — `hasGroupPermission()` (~917-925) is the default-open gate; `evaluatePermission()` falls through to the `public` group for unauthenticated requests.
- `lib/Service/ObjectService.php` — `checkSavePermissions()` (~1320-1360) is the object create/update authorization entry point.
- `lib/Db/MagicMapper/MagicRbacHandler.php` — `buildMatchConditions()` (~356-377) / `buildPropertyCondition()` (~463-466) build the SQL RBAC predicate; the null-resolution branch drops the predicate instead of emitting an impossible condition.
- `lib/Service/ConditionMatcher.php` — the fail-closed reference behaviour (`singleConditionMatches` returns false on null dynamic value, ~144-146).
- #1949 established the **default-secure** precedent for model CRUD; this change extends fail-closed semantics to object writes and to the SQL match evaluator.
- **Out of scope / preserved:** legitimate public-submission use cases — a schema that *wants* anonymous writes can still declare a `public` `create`/`update` rule; only the *implicit* default for anonymous changes. Object **read** default-open is NOT changed here (separate policy question); this change only makes the SQL match evaluator agree with the PHP one (no new denials for resolvable rules).

## Proposed Solution

1. **Fail-closed object writes for anonymous callers.** In the object create/update authorization path, when the resolved principal is anonymous (no `IUserSession` user) and no authorization rule explicitly grants the `public` group the requested write action, DENY (403). Authenticated users are unaffected by this change (their default-open behaviour is a separate, larger policy decision tracked in #1955). This closes the anonymous write hole while preserving declared public-submission schemas.

2. **Fail-closed SQL match evaluation.** In `buildMatchConditions`/`buildPropertyCondition`, a `match` property whose dynamic value resolves to `null` MUST emit an impossible predicate (`1 = 0`) for that rule rather than being dropped — mirroring `ConditionMatcher`'s fail-closed semantics — so the LIST and FIND paths produce identical verdicts. A shared test asserts list==find for unresolved-variable rules (both single- and multi-condition).

## Capabilities

| Capability | Type | Action |
|---|---|---|
| `auth-system` | backend | **Modified** — adds: object writes fail closed for anonymous unless a public-write rule is declared; SQL/list RBAC `match` evaluation fails closed on null-resolved dynamic variables (parity with the PHP/find path) |

## Out of scope
- Authenticated-user object-write default-open policy (broader decision; tracked in #1955).
- Object-read published/visibility enforcement (separate finding family).
- openregister #1952 (downgraded — gated by NC readability) and #1954 (dead branch) — not addressed; both reclassified LOW after runtime verification.
