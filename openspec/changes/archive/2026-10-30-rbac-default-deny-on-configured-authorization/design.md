## Context

OpenRegister evaluates schema `authorization` blocks through three enforcement paths that share the same rule grammar but emit different artefacts:

1. **Single-object / schema-level PHP verdict** — `lib/Service/Object/PermissionHandler.php::hasGroupPermission()` (returns a boolean for one action on one schema/object).
2. **Row-level PHP verdict** — `lib/Db/MagicMapper/MagicRbacHandler.php::hasPermission()` (boolean, per fetched object).
3. **SQL list/search filter** — `lib/Db/MagicMapper/MagicRbacHandler.php::applyRbacFilters()` (appends WHERE conditions to a QueryBuilder so list/browse only returns visible rows).

All three currently share the same fail-*open* default for a **non-empty but partial** authorization block: if the block does not list the requested action, the path returns "allowed" (paths 1 and 2) or appends no filter (path 3). Conditional match evaluation is delegated to the shared `ConditionMatcher` service (ADR-011); this change does **not** touch match evaluation, only the default verdict for an *omitted* action.

The motivating context (see `proposal.md`): PR #185 made object CRUD endpoints `@PublicPage`, removing the Nextcloud app/group gate (HTTP 412) that previously fronted every request. OpenRegister's RBAC is now the sole gate, so the fail-open partial-config default is a live exposure.

## Goals / Non-Goals

**Goals:**
- On a non-empty effective authorization block, deny any action not explicitly granted — including for the `public`/unauthenticated pseudo-group.
- Keep the three enforcement paths in lock-step so single-GET, row-level, and list/search agree.
- Preserve open default-allow when the authorization block is empty.
- Preserve the admin-group bypass and the object-owner bypass unchanged.
- Encode the SQL-path denial using the existing `1 = 0` impossible predicate (no new deny mechanism).

**Non-Goals:**
- Changing the global `rbac.enabled` switch in `ConfigurationSettingsHandler` (orthogonal on/off; stays default `true`).
- Changing the per-call `$_rbac=false` short-circuit (admin context / RBAC-not-enforced still allows).
- Changing property-level RBAC defaults (`PropertyRbacHandler`).
- Introducing a new ConditionMatcher or any new match evaluator (ADR-011).
- Adding new schemas, registers, or seed objects.

## Decisions

### Decision 1: Edit the three enforcement paths together
Apply the same fail-closed flip in all three paths so verdicts cannot diverge. Each edit only changes the "action key absent / empty rule list on a non-empty block" branch; the `empty($authorization)` open-default branch immediately above each one **stays**.

- **`PermissionHandler.php::hasGroupPermission()` (~line 1017).** Today:
  `if (isset($authorization[$action]) === false) { return true; }`.
  Change to deny on an absent/empty action: `if (empty($authorization[$action]) === true) { return false; }`.
  The `empty($authorization) === true` open-default at ~line 1012 is left intact, as are the admin bypass (~line 1002) and the object-owner bypass (~line 1007), which both precede it.
- **`MagicRbacHandler.php::hasPermission()` (row-level PHP, ~line 884).** Today, after computing `$rules = $authorization[$action] ?? []` (~line 881):
  `if (empty($rules) === true) { return true; }`.
  Change the verdict to `return false;`. The admin bypass (~line 863) and owner bypass (~line 868) precede it and stay; the `empty($authorization)` open-default (~line 876) stays.
- **`MagicRbacHandler.php::applyRbacFilters()` (SQL list path, ~line 195).** Today there is an early-return for an unconfigured action (~lines 195–202: "If action is not configured in authorization, it's open to all"). **Delete that early-return** so flow falls through to the existing logic that builds the owner-only condition (~line 208) and, when no conditions accumulate, appends the existing deny-all impossible predicate `1 = 0` (~lines 247–260). Admins already returned earlier (~line 147 region) so they are unaffected, and the owner condition is preserved. This is what makes the list path consistent with single-object: an omitted write action now yields owner-only rows or `1 = 0`, never the full table.
- **`MagicRbacHandler.php::buildRbacConditionsSql()` (raw-SQL/UNION variant, ~line 996).** Discovered during implementation: a *fourth* path — the raw-SQL equivalent of `applyRbacFilters()` used by `MagicSearchHandler` (~line 575) for UNION-based search — carried the identical omitted-action open-default (`return ['bypass' => true, 'conditions' => []]`). Left unflipped it would diverge from the other three (search returns a row single-GET denies — an IDOR-style leak, exactly the "Path divergence" risk below). **Remove that early `bypass => true` return** so flow falls through to the owner condition and the documented "empty conditions ⇒ deny all" contract (`bypass => false`). Admins already returned; the `empty($authorization)` open-default above stays.
- **`lib/Db/Schema.php::hasPermission()` (~line 971).** Also discovered during implementation: a *fifth* implementation of the same rule grammar, on the `Schema` entity itself (it even carries its own `evaluateMatchConditions`, an ADR-011 duplication). It has the identical omitted-action open-default. A repo-wide search found **no `lib/` enforcement callers** — it is currently dead/legacy (only exercised by `tests/unit/Service/RbacTest.php`). Flipped here anyway (`isset(...) === false ⇒ return true` → `empty(...) === true ⇒ return false`) so a future re-wiring cannot silently reintroduce the hole and so all implementations of the rule agree. Its match-evaluation duplication is left as-is (out of scope; tracked implicitly as a future ADR-011 cleanup).

**Alternative considered:** a single shared helper returning the default verdict, called by all three paths. Rejected for this change — the three paths return structurally different artefacts (bool vs. QueryBuilder mutation) and already share `ConditionMatcher` for the genuinely shared logic; consolidating the default branch would be a larger refactor than the surgical flip and is out of scope. Recorded as a possible future cleanup.

### Decision 2: Fix the now-stale docblocks
Two docblocks describe the *old* "action not specified → everyone has permission" default and become incorrect after Decision 1:
- `PermissionHandler.php` ~lines 195–196 (the `checkPermission`/class-level "If no authorization configured / action not specified" rule list).
- `PermissionHandler.php` ~lines 962–964 (the `hasGroupPermission` rule list: "If authorization is set but action is not specified, everyone has permission").
Both MUST be updated to state the new fail-closed default for omitted actions on a non-empty block, while keeping the empty-block open-default and the admin/owner bypass descriptions accurate.

### Decision 3: Exclude PropertyRbacHandler
`lib/Service/PropertyRbacHandler.php` (~line 270) keeps its "no property-level authorization ⇒ follow object-level rules" default. Property-level absence is **not** a deny: a property without its own block inherits the object-level decision, which is the correct and intended layering. Flipping it would deny *reads of ordinary fields* on any schema that uses property-level rules for only a few sensitive fields — a regression, not a fix. So this file is explicitly not in scope.

### Decision 4: List/search enforces `read`, not `list`
The list/search path (`MagicSearchHandler.php` lines ~575, ~1058, ~1090) enforces `action: 'read'`. There is no separate `'list'` action. Consequence: schemas that explicitly grant `read` (including `read: ["public"]`) keep both read and browse open after the change; only the write actions lock down. This bounds the breaking surface and is asserted in the spec scenarios.

### Decision 5: Deny encoding and logging
The SQL deny continues to use the existing `IMPOSSIBLE_SQL_CONDITION` (`1 = 0`) constant; no new SQL deny mechanism is added. Any debug logging on the deny branches MUST remain metadata-only (action, userId, schema id) and MUST NOT log object values or PII, per ADR-005. The existing deny-all debug log at ~line 249 already follows this.

## Risks / Trade-offs

- [Silent lockout of write flows on partially-configured schemas] → This is the intended security fix, but it is **BREAKING**. Mitigation: the installed-config audit (below) enumerates every affected schema; the Migration Plan calls out the one read-side flip (`docudesk` Publication Prohibition) explicitly so it is fixed before/with rollout.
- [`docudesk` Publication Prohibition loses read/list for non-admin/non-owner] → Its config omits `read`. Mitigation: cross-app follow-up to add an explicit `read` grant in `docudesk/lib/Settings/docudesk_register.json`; flagged in tasks.md. This file is outside OpenRegister's tree, so OpenRegister cannot fix it directly.
- [opencatalogi publication writes become admin/owner-only] → Behaviour change for curated content. Mitigation: documented in the audit so opencatalogi maintainers can add explicit write grants if non-admin curation is required.
- [Test suites assert the old open default] → PHPUnit RBAC tests and the Newman RBAC/IDOR suite currently encode "partial config ⇒ open". Mitigation: update them as part of this change (tasks.md). Treat any *remaining* green test asserting old-open as a missed update, not a pass.
- [Path divergence] → If only some of the three paths are flipped, list and single-object verdicts disagree (IDOR-style leak). Mitigation: all three edits are a single atomic change with cross-path consistency scenarios in the spec.

## Test Impact

- **PHPUnit:** existing `PermissionHandler` and `MagicRbacHandler` RBAC unit tests that assert "action not configured ⇒ allowed" must be inverted to assert deny, and new cases added for: omitted write on `read`-only schema (deny for member and for public), admin bypass intact, owner bypass intact, empty-block open-default intact, and SQL path producing `1 = 0` / owner-only.
- **Newman (RBAC / IDOR suite):** any scenario that exercises a partially-configured schema and expects HTTP 200/201 on an unconfigured write action must be updated to expect 403; add a positive scenario confirming `read`/browse still works when `read` is granted to `public`.

## Seed Data (ADR-016)

This change introduces **no new schemas, registers, or seed objects** — it changes an enforcement default, not the data model. Per ADR-016, in lieu of new seed data this section documents the **authorization-config review** that the default change forces on already-installed register configs (`*/lib/Settings/*_register.json`; the `docs/static/oas/*` files are illustrative examples, not installed):

- **Read-only schemas (20) — write lockdown, read+browse unaffected; review only:** `bag` (Nummeraanduiding, Verblijfsobject, Pand), `brp` (Ingeschreven Persoon), `kvk` (Maatschappelijke Activiteit, Vestiging), `ori` (Vergadering, Agendapunt, Raadsdocument, Stemming, Raadslid, Fractie), `opencatalogi` publication register (Publication, Catalog, Listing, Organization, Page, Theme, Menu, Glossary). For bag/brp/kvk/ori the write lockdown is the desired secure posture (these are external reference registers); no config edit required. For the opencatalogi publication schemas, confirm whether non-admin curation is expected and, if so, add explicit `create`/`update`/`delete` grants.
- **Read-side flip (1) — config edit required, cross-app:** `docudesk` **Publication Prohibition** configures `create`/`update`/`delete` but omits `read`; after this change read/list deny for non-admin/non-owner. It needs an explicit `read` grant. This config lives in the `docudesk` repo, outside OpenRegister — handled as a follow-up.
- **Unaffected:** `dso`, `n8n_workflows` (no authorization blocks → open default preserved; no review needed).

The corresponding task is to *review and, where needed, adjust* these authorization blocks — not to generate new seed objects.

## Migration Plan

1. Land the three enforcement edits + docblock fixes together (single PR to `beta`).
2. Update PHPUnit and Newman suites in the same PR so CI reflects the new default.
3. Before/with rollout, add the explicit `read` grant to `docudesk` Publication Prohibition in the `docudesk` repo (cross-app follow-up tracked from this change's tasks).
4. Communicate the opencatalogi publication-write lockdown to opencatalogi maintainers; they add explicit write grants if curated non-admin writes are required.
5. **Rollback:** the change is config-default behaviour only (no DB migration, no schema change). Reverting the three code edits restores prior behaviour immediately; no data migration is needed in either direction.

## Open Questions

- Whether the opencatalogi publication schemas should ship explicit write grants in this change or be left to opencatalogi maintainers. Provisional: leave to opencatalogi maintainers and flag in the audit, since those configs live in the opencatalogi tree.
