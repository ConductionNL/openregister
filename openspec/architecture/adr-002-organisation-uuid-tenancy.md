# ADR-002: Multi-tenancy via organisation UUID, not Nextcloud groups

**Status**: accepted (documents the decision as implemented)

**Date**: 2026-07-07

## Context

OpenRegister scopes registers, objects, and users to tenants. Nextcloud ships a
native group system (`IGroupManager`), and the obvious-looking design is to use
NC groups as tenants. OpenRegister deliberately does not do that: tenancy is
carried by a first-class `Organisation` entity keyed by UUID, persisted in
`openregister_organisations` (`lib/Db/OrganisationMapper.php`), and threaded
through the query path (`lib/Service/Object/SearchQueryHandler.php`,
`lib/Service/RequestScopedCache.php`, `lib/Db/RealtimeEventMapper.php`).

The decision was load-bearing from the start but never written down. Consuming
apps and reviewers keep re-deriving it — and getting it wrong breaks per-tenant
isolation silently, because a query scoped to an NC group id simply matches
nothing (or worse, everything, when the filter is dropped).

## Decision

**A tenant is an `Organisation` object identified by UUID. Nextcloud groups are
never a tenancy boundary in OpenRegister.**

### Numbered rules

#### Rule 1 — Organisation UUID is the only tenant key

Every tenant-scoped query, cache key, event stream, and RBAC evaluation uses
the organisation UUID from `openregister_organisations`. Code MUST NOT use an
NC group id, group display name, or `IGroupManager` membership as a tenant
discriminator.

**Rationale.** Organisations carry OR-specific state (default register
settings, membership metadata) that NC groups cannot; and NC group ids are
admin-editable strings, unsuitable as stable foreign keys.

#### Rule 2 — Membership resolves through OrganisationMapper

"Which tenants does this user belong to?" is answered by
`OrganisationMapper` (user–organisation relationship rows), not by group
membership. Consuming apps needing tenant context call OR's APIs; they do not
reimplement the join.

#### Rule 3 — NC groups remain valid for RBAC principals, not tenancy

Schema/register `authorization` blocks may reference NC groups as *permission
principals* (who may read/write). That is orthogonal to tenancy: a group can
appear in RBAC while the tenant boundary stays the organisation UUID.

## Consequences

- (+) Stable, rename-proof tenant keys; tenant metadata lives with the tenant.
- (+) Cross-app tenancy contract is a single mapper, testable in isolation.
- (−) Two membership systems coexist (NC groups for RBAC principals,
  organisations for tenancy); onboarding docs must state the split explicitly.
- Follow-up: consuming-app docs should link this ADR wherever `organisation`
  appears in an API contract.
