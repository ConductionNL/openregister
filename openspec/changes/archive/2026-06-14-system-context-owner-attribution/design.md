## Context

Two OR code paths conspire to hide service-written rows:

1. **Write path** — `SaveObject::prepareObjectForCreation()` (lib/Service/Object/SaveObject.php:3417) reads `IUserSession::getUser()`. If null, it skips `setOwner()` entirely, so the new row persists with the column default (`''`).
2. **Read path** — `MagicRbacHandler::applyRbacFilters()` (lib/Db/MagicMapper/MagicRbacHandler.php:178) emits `_owner = <currentUserId>` as the user-self-access condition. For an unauthenticated row (`_owner=''`) the only way to be visible is to satisfy a schema authorization rule. The full admin bypass at line 147 IS hit when the requesting user is in the `admin` group, BUT in chain-E the reproduction shows admin still gets `total: 0`. That is because the list endpoint is reached via the openconnector controller chain which proxies to OR — by the time we get to RBAC we have a user, but the multi-tenancy filter (`MagicOrganizationHandler::applyOrganizationFilter` lib/Db/MagicMapper/MagicOrganizationHandler.php:93) excludes null/empty `_organisation` rows for non-admin code paths.

Even when the row has `_organisation` set, the empty `_owner` means non-admin users in the same org can't see it because every authorization rule the schema configures (e.g. "users in `log-readers` group can read") doesn't have a matching condition for "service-owned rows".

The cleanest fix is to never persist an empty `_owner` in the first place, and to make the chosen identifier semantically visible: it is a system identifier, and admin-of-org can see it.

## Goals / Non-Goals

**Goals:**
- New service writes get a non-empty `_owner` that uniquely identifies them as system-attributed.
- Admin users see system-owned rows on REST list (the chain-E dashboard works).
- A deployment can opt extra groups (e.g. `log-readers`, `audit-readers`) into seeing system rows without giving them admin.
- Organisation isolation continues to hold — admin-of-org-A cannot suddenly read system-owned rows in org-B unless they were admin-of-org-B already.

**Non-Goals:**
- Backfill of existing rows with `_owner=''`. Operators can run a one-off SQL UPDATE; we don't migrate automatically.
- A per-organisation system identity. One identifier per deployment, period. Per-org tenant separation is preserved by `_organisation`, not by `_owner`.
- Changing audit-trail provenance. `AuditTrailMapper` continues to record whatever actor it sees; system writes appear with empty actor on the audit row, which preserves the forensic distinction "the object's owner is `__system__`" vs "the object was modified by a user who happens to be called `__system__`" (impossible — see Decisions).
- ADR-023 action-level authorization. Out of scope.

## Decisions

### D1 — Fix the source, not the filter

**Decision:** Set `_owner = <systemUserId>` on save when no user session exists. Add a small RBAC visibility carve-out for that owner.

**Alternatives considered:**

- **A. Filter-only fix.** Change `MagicRbacHandler` to also OR-in `_owner IS NULL OR _owner = ''` for admins. Rejected — it papers over the symptom: rows still ship with empty `_owner`, which means every downstream consumer (audit dashboards, BI, OpenSearch sync) has to keep coding around the empty-string case. The data stays semantically wrong.
- **B. Fix-source-only.** Set `_owner = systemUserId` but don't touch RBAC. Rejected — without the carve-out, the only users who can see system rows are admins (via the line-147 admin-bypass) and that's not configurable. Many deployments want a dedicated `log-readers` group that isn't `admin`.

This is the layered approach the issue asked for; we do both.

### D2 — Identifier choice: `__system__`

**Decision:** Default `openregister.systemUserId = '__system__'`. The double-underscore prefix is forbidden in Nextcloud's user-ID validator (`OC\User\Manager::validateUserId` rejects `__` prefix), so no real user can collide. The identifier is config-overridable for deployments that want a different convention.

**Alternative considered:** the nil UUID `00000000-...`. Rejected — UUID-shaped `_owner` values would confuse audit-trail readers who expect a UID-shaped string there.

### D3 — Carve-out granularity: `admin` group + configurable `systemReaderGroups`

**Decision:** Two-tier visibility. Admins always see system rows (they already bypass RBAC entirely via line 147, but we add `_owner = systemUserId` as an explicit OR-condition for completeness — see D5). A deployment can additionally widen visibility by listing groups in `openregister.systemReaderGroups`.

**Alternative considered:** A new schema-level `authorization.read` rule keyword like `{ "system": true }`. Rejected — every schema would need the keyword added explicitly; deployment-wide visibility is the right granularity for "I as the operator decided this group reads service-written logs everywhere."

### D4 — Single service for system identity

**Decision:** Add methods on the existing `OrganisationService` — `getSystemUserId(): string` and `getSystemReaderGroups(): array`. Both `SaveObject` and `MagicRbacHandler` call them. Single source of truth, no parallel implementations.

**Alternative considered:** A new `SystemIdentityService` class. Rejected for now — it would be 30 lines with two methods. If/when more "system identity" concerns appear, we extract. (Refactor signal: a third caller asks for the same value.)

### D5 — RBAC carve-out is layered ABOVE the existing admin bypass

**Decision:** Keep the existing admin-group full-bypass at line 147 unchanged. Add the system-owner OR-condition AFTER the admin-bypass return — so for admin users the carve-out is dead code (they already saw everything), but for `systemReaderGroups` members it's the only thing letting them see system rows. This keeps the admin contract intact and isolates the new behaviour to the new code path.

### D6 — Declarative vs imperative (ADR-031)

Not applicable. This is a security/auth code change, not a schema-register behaviour. The change touches no lifecycle, aggregation, calculation, notification, relation, or widget logic. Per ADR-031 § "Exception: lifecycle guard / scheduled bulk / external integration / domain rule", security-filter code legitimately lives in service classes, not schema JSON.

## Seed Data

Not applicable — this change introduces no new OpenRegister schemas. The behavioural change applies to all existing schemas that record `_owner` (i.e. every register table). ADR-001 seed-data section is not required for a security/visibility fix that doesn't introduce schema.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Existing rows with `_owner=''` remain hidden | Document the one-shot SQL: `UPDATE oc_openregister_<table> SET _owner = '__system__' WHERE _owner = '' OR _owner IS NULL;`. Not auto-run — operators audit first. Tracked in proposal "Backwards compatible". |
| Misconfigured `systemReaderGroups` grants over-broad visibility | App-config keys are admin-only writable. Documentation in proposal warns operators. Default is empty so the carve-out is opt-in beyond admin. |
| New OR-condition in the RBAC SQL slightly increases query plan complexity | The condition is a constant-string equality against an indexed column (`_owner` is indexed on every register table per the standard schema). Added cost is negligible (sub-ms). |
| Test environment uses `__system__` as a real test fixture and collides | We verified NC's user-manager rejects `__` prefix; cannot collide with a real user. If a test fixture inserts a raw row with `_owner='__system__'`, it is by definition a system-attribute row — that's the intended semantics. |
| Audit-trail provenance becomes ambiguous | `AuditTrailMapper` is NOT modified. System writes still record the actor as `null`/empty on the audit row. The data row says `_owner=__system__`; the audit row says "no user". A forensic reader sees both and knows the distinction. |

## Migration Plan

**Deploy:**
1. Merge PR. New session-less writes immediately get `_owner = '__system__'` (config key unset → default).
2. Operators who want non-default reader groups set `php occ config:app:set openregister systemReaderGroups --value="log-readers,audit-readers"`.

**Rollback:** Revert the PR. Any rows written between deploy and rollback will have `_owner='__system__'` — they remain visible to admin (no change required) but non-admin reader groups lose access (their config key becomes a no-op). No DB rollback needed.

**Backfill (optional, manual):**
```sql
-- For each register's data table, identified by oc_openregister_<id>_<id>:
UPDATE oc_openregister_table_<id> SET _owner = '__system__' WHERE _owner = '' OR _owner IS NULL;
```
Run per table during a maintenance window. Not part of this PR.

## Mixed-spec rationale

Not applicable — `kind: code`, no config surface touched (the two new app-config keys are runtime configuration, not schema-register JSON).

## Open Questions

- Should `systemReaderGroups` be a per-schema setting instead of a deployment-wide one? Deferred — current scope is "make dashboards work"; per-schema granularity is overkill for that and adds spec surface. Revisit if a deployment asks for it.
