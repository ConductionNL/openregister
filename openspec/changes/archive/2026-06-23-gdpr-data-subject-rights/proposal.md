---
kind: code
---

## Why

GDPR / AVG data-subject rights (access, rectification, erasure, restriction,
portability, objection) apply to **every** Conduction app that holds personal
data. Today each app re-implements the same plumbing — a request object with a
status lifecycle, the EU art-12 1-month legal deadline (single +2-month
extension), cross-register discovery of a subject's data, and an immutable audit
of how the request was handled. pipelinq shipped the most complete version
(`lib/Service/Avg/*` + `40-avg-verzoeken.json`), but most of that code is the
**generic GDPR mechanic**, not Dutch policy.

OpenRegister is the layer that holds the data **and** the PII index
(`openregister_entities` ⋈ `openregister_entity_relations`, the `GdprEntity`
model) and already owns the privileged `DsarService` (admin-gated cross-register
discover/erase/rectify), `RetentionService` (legal-hold + immutability guard),
and the immutable, hash-chained audit trail (`AuditTrailMapper` +
`AuditHashService`). It is the right home for a **consumable, RBAC + tenant
scoped** data-subject-request capability so apps stop re-implementing it.

## What Changes

- **A `dataSubjectRequest` object model** — an OR-shippable register/schema
  capturing the subject identifier, the request `type` (GDPR art-15 access /
  16 rectification / 17 erasure / 18 restriction / 20 portability / 21
  objection), a status lifecycle (`received → verifying → in-progress →
  fulfilled | refused → closed`) declared via the existing
  `x-openregister-lifecycle` annotation, and the EU art-12 legal deadline
  (`dueAt` = `receivedAt` + 1 month, single `extend()` of +2 months recorded in
  `extendedUntil`). Lifecycle transitions on this object are auto-audited by the
  existing `AuditTrailMapper` hash-chained trail. **Generic, not
  jurisdiction-specific** — no BSN, no AP reference, no FG/DPO wording.

- **A pure `DataSubjectDeadline` helper** — DB-free, fully unit-testable EU
  art-12 deadline math: `computeDueAt()`, `extend()` (once, +2 months),
  `isOverdue()`, `daysRemaining()`.

- **A consumable `DataSubjectRequestService`** — DI-resolvable by any app,
  **RBAC + tenant scoped, NOT admin-gated** (the admin-only `DsarService` stays
  as the privileged cross-tenant amplifier; this is the surface a leaf app calls
  on behalf of an authenticated handler). It reuses the `GdprEntity` index join
  and `RetentionService` legal-hold guard:
  - `findSubjectData()` — discover a subject's objects across registers,
    RBAC/tenant-scoped and field-aware.
  - `assembleAccessExport()` — art-15/20 portable bundle of the subject's data.
  - `rectify()` — art-16 single-object correction with DSAR attribution.
  - `erase()` — art-17 erasure with a **mode parameter** (`pseudonymise`
    field-level vs `whole-object` soft-delete — apps differ, so it is not
    hard-coded) that **respects legal hold + immutable archival status**
    (objects under hold are reported as `held`, never erased).
  - `setRestriction()` / `setObjection()` — art-18 / art-21 flags.
  - deadline helpers delegating to `DataSubjectDeadline`.

- **An HTTP surface** (`DataSubjectRequestController` + routes) so apps can drive
  the capability over REST. Endpoints are `#[NoAdminRequired]` and rely on the
  service's RBAC/tenant scoping (defence-in-depth), distinct from the admin-only
  `DsarController`.

This is **additive and backward-compatible**: `DsarService`/`DsarController`,
`AvgRetentionService`, and `RetentionService` are untouched. The new schema lives
in its own register; no existing schema changes. The Dutch-locale policy (AP
complaint reference, FG/DPO naming, citizen correspondence, 4-eyes, RvIG
retention, BSN/BRP) stays in the consuming app (pipelinq) as a thin overlay.

## Capabilities

### New Capabilities

- **gdpr-data-subject-rights** — the generic, consumable data-subject-request
  model + lifecycle + EU art-12 deadline + cross-register find/fulfil service.
