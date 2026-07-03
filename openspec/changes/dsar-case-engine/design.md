## Context

The declarative head `dsar-case-subsystem` (kind:config, merged first in the chain) extends
`lib/Settings/data_subject_request_register.json` so the `dataSubjectRequest` schema is a stateful
**case**: an N-state `x-openregister-lifecycle` (`received → verifying → in-progress →
fulfilled/refused/closed`, plus `assign`/`collectEvidence`/`draftDenial`/`finaliseDenial`/`redact`/
`bundle`/`retain`), deadline-tracking `x-openregister-calculations`/`-aggregations`, and
advance-reminder/escalation/breach `x-openregister-notifications`. It declares the **shape** of the
`evidence` and `redactions` sub-collections, the `denialGround` enum, and the
`retentionWindow`/`retainUntil`/`purgedAt` stamps — but it ships **no runnable behaviour** for
them, and its `finaliseDenial` transition references a `requires` guard FQCN that does not yet
exist.

OpenRegister already ships the stateless DSAR engine this change builds on
(`lib/Service/Gdpr/DataSubjectRequestService.php`): `assembleAccessExport(subjectId, type?)`,
`erase(subjectId, type?, eraseMode, dryRun)` (defaulting to `ERASE_MODE_PSEUDONYMISE`),
`rectify`/`setRestriction`/`setObjection`, plus the art-12 maths via `DataSubjectDeadline`
(`computeDueAt`/`extend`/`isOverdue`/`daysRemaining`). Retention primitives live in
`lib/Service/RetentionService.php` (`hasActiveLegalHold`, `validateNotImmutable`,
`findEligibleForDestruction`) and there is an existing `AvgRetentionJob`/`TimedJob` scheduled-work
pattern under `lib/BackgroundJob/`. Every write is pinned to the DSAR processing activity via
`ObjectEntity::setProcessingActivityId()` so the immutable hash-chained `AuditTrailMapper` records
it. Existing DSAR routes live under `/api/gdpr/*` (`DataSubjectRequestController`) and `/api/avg/*`
(`DsarController`).

This change is the **imperative successor** (config head → this engine → Phase 2
`dsar-policy-pack-and-seams`). It supplies the ADR-031 imperative exceptions the head deferred, so
that a register with both changes has a fully operational tracked workflow.

Constraints:
- All case reads/writes flow through `ObjectService` (RBAC + multitenancy); no custom Entity/Mapper
  (ADR-001). Case-level access control (handler-scopes-own + officer override, ADR-023) layers on
  top, it does not replace object RBAC.
- Reuse the existing stateless primitives; do not re-implement export assembly, deadline maths,
  erase, or legal-hold awareness (ADR-011).
- Endpoints are authenticated steward endpoints (`@NoAdminRequired`), never `@PublicPage`; every
  method is registered in `appinfo/routes.php` and reachable (ADR-005/016/029).

Stakeholders: the OR team (owns the engine + provider/guard/job contracts), Phase 2
`dsar-policy-pack-and-seams` (binds jurisdiction data + integration seams), pipelinq (reference
migrator, later).

## Goals / Non-Goals

**Goals:**
- An `EvidenceSourceProvider` interface + a registry (ADR-019 style) so leaf apps register harvest
  sources; async collection that dedups by content hash and tracks per-item `status` onto the
  case's declared `evidence` sub-collection.
- An export-bundle service: assemble via `DataSubjectRequestService::assembleAccessExport`, sign
  (PAdES-LTV / SHA-256), mint a one-time secure download token, and assemble a regulator dossier
  from the case's evidence + redactions + audit trail.
- A field-level redaction write path recording before/after + ground through the audit trail,
  distinct from erase-time pseudonymise.
- The `finaliseDenial` lifecycle guard class enforcing the mandatory `regulatorReference`
  precondition (the FQCN the head's transition references).
- A scheduled, legal-hold-aware retention sweep (`TimedJob`): hard-delete dossiers past
  `retainUntil`, scrub evidence PII via `erase(mode=pseudonymise)`.
- Case-management REST controllers/routes with correct auth posture + a case-level access-control
  check.

**Non-Goals:**
- No jurisdiction policy-pack config contract and no identity-verify (BSN/BRP/RvIG) /
  regulator-escalate (AP) integration seams — that is Phase 2 `dsar-policy-pack-and-seams`,
  referenced only as `depends_on` successor context. `BsnFormat` is noted for the Phase-2 author,
  not used here.
- No change to the declarative head's register (schema, lifecycle graph, denial-ground enum,
  notification rules) — this change **consumes** it. No new OR schema is added.
- No change to the existing stateless fulfilment behaviour — it is reused verbatim.
- No frontend / Vue (the full `AvgIndex.vue` case-management surface is a Phase-2 concern per
  ADR-047's migration sequencing).

## Decisions

### Declarative-vs-imperative decision (ADR-031)

The head owns the declarative column (lifecycle, calculations, aggregations, notifications, schema
shape). This change is `kind: code` — every item below is a **legitimate ADR-031 imperative
exception**, not a declarative construct that was reached for in code. The default is declarative;
each entry justifies why the schema engine cannot express it:

| Behaviour | Chosen path | ADR-031 rationale (why imperative, not declarative) |
|---|---|---|
| **Pluggable evidence-source providers + async harvest + content-hash dedup + per-item status** | **Imperative** — `EvidenceSourceProvider` interface + registry + harvest service | External-source harvest via provider adapters reaching outside OR (OpenConnector et al.) is exactly the ADR-003/ADR-031 "external API integration" exception; the schema engine cannot reach external systems, compute content hashes, or drive async collection. The head declared only the `evidence` sub-collection *shape*; the harvesting is code. Registry style follows ADR-019. |
| **Signed export bundle** (assemble + PAdES-LTV/SHA-256 sign + one-time download token + regulator dossier) | **Imperative** — export-bundle service | Document generation / PAdES signing / rendered signed bytes is the ADR-031 document-generation exception; the schema engine has no opinion on rendered/signed output. The one-time token is a security credential minted + burned at request time — a stateful side effect, not a declarative field. |
| **Field-level redaction write path** (apply before/after + ground, audit it) | **Imperative** — redaction-write service | The head declared the `redactions` sub-collection shape; *capturing* the before/after at redaction time and recording it to the immutable trail is a write operation with side effects — it belongs to the engine, and must be distinct from `erase(mode=pseudonymise)`. |
| **Denial finalise guard** (mandatory `regulatorReference` before finalise) | **Imperative** — a short lifecycle guard class (`requires` FQCN) | ADR-031 §3 explicitly permits a short PHP guard as a lifecycle transition precondition. The head *declared* the `finaliseDenial` transition with a `requires` reference to this class; this change *authors* the class. It is the legitimate PHP seam, not business logic smuggled out of the schema. |
| **Retention sweep** (hard-delete dossier + scrub evidence PII on a schedule, legal-hold aware) | **Imperative** — a `TimedJob` | Scheduled bulk work over an object queue with destructive side effects (delete/scrub) is the ADR-031 external-orchestrator / scheduled-work exception: use a `TimedJob`, not a declarative construct. The head declared the *windows*; the *sweep* is code. Reuses the `AvgRetentionJob`/`TimedJob` pattern + `RetentionService` legal-hold awareness. |
| **Controllers / routes / case-level access control** | **Imperative** — controller(s) + routes + access-control check | REST endpoints and request-time authorization are inherently imperative (ADR-002/005/016/023); they are the transport for the above services. The case-level check (handler-scopes-own + officer override) layers on OR object RBAC, it does not re-implement it. |

Nothing in this table is a materialised per-object calculation, a cross-object aggregation, a
lifecycle state set, or a notification-on-condition — those are the head's, and re-doing any of
them in code here would be an ADR-031 (gate-18/gate-31) violation.

### Reuse before build (ADR-011)

Searched `lib/Formats/`, `lib/Service/`, `lib/Service/Gdpr/`, `lib/BackgroundJob/`, `lib/Cron/`,
and `appinfo/routes.php` before proposing anything. Reuse, do not duplicate:

- **`DataSubjectRequestService::assembleAccessExport(subjectId, type?)`** — the export-bundle
  service assembles from this; it does **not** re-implement subject-data discovery/assembly. Bundle
  signing wraps the assembled payload.
- **`DataSubjectRequestService::erase(subjectId, type?, eraseMode=ERASE_MODE_PSEUDONYMISE,
  dryRun)`** — the retention sweep scrubs evidence PII through this (pseudonymise mode); it does not
  hand-roll a scrub. The redaction write path is deliberately **distinct** from this erase.
- **`DataSubjectDeadline`** — reused verbatim for any deadline reference the engine needs; the head
  already declares the derived tracking fields. No second deadline implementation.
- **`RetentionService::hasActiveLegalHold` / `validateNotImmutable`** — the sweep consults these
  before any hard-delete/scrub so a legal hold blocks destruction (same guarantee erase already
  honours).
- **`AuditTrailMapper` + hash chain + `ObjectEntity::setProcessingActivityId()`** — every case
  transition, redaction, evidence attach, and sweep action is audited through the existing
  immutable trail pinned to the DSAR processing activity; no parallel audit log (ADR-022).
- **`AvgRetentionJob` / `OCP\BackgroundJob\TimedJob` + `IAppConfig` toggle/dry-run pattern** — the
  retention sweep follows this exact shape (an enabled toggle + a dry-run toggle) rather than a
  bespoke scheduler.
- **`BsnFormat` (`lib/Formats/BsnFormat.php`)** — reserved for Phase-2 identity verification; not
  used here, noted so the Phase-2 author reuses it.
- **`DataSubjectRequestController` / `DsarController` + the `/api/gdpr/*` and `/api/avg/*` route
  conventions** — the new case-management endpoints extend this surface (register in the same
  `appinfo/routes.php`, same auth-annotation conventions) rather than replacing it.

### Provider interface + registry (ADR-019)

`EvidenceSourceProvider` is a narrow interface (identify + harvest-for-subject → items carrying a
`sourceId`, a `contentHash`, and a `status`). A registry collects the providers a leaf app
registers; the harvest service iterates registered providers, dedups items by `contentHash` (an
item whose hash already exists on the case is skipped, not re-appended), and writes each item's
per-item `status` onto the case's declared `evidence` sub-collection through `ObjectService`. This
mirrors the existing OR registry/integration surface and keeps external systems (OpenConnector)
behind the seam.

### Object / access-control patterns (ADR-001, ADR-022, ADR-023)

All case objects are OR objects under the `data-subject-requests` register / `dataSubjectRequest`
schema; reads/writes flow through `ObjectService` (RBAC + multitenancy). No custom Entity/Mapper.
The case-level access-control check is a thin authorization layer **on top of** object RBAC: a
handler may act on cases assigned to them (handler-scopes-own); a configured officer role may
override across cases. It never widens access beyond what object RBAC already grants — it only
narrows a broadly-authorized user to their own cases unless they hold the officer role. It fails
closed (a resolver returning null/unavailable denies, never skips — avoids the CWE-863 fail-open
pattern).

### Endpoint shapes (ADR-002, REST / Common Ground)

Provisional case-management routes, aligned with the existing `/api/gdpr/*` conventions
(exact shape confirmed in Open Questions):
- `POST /api/gdpr/cases` → create a case.
- `POST /api/gdpr/cases/{id}/transition` → run a declared lifecycle transition (incl.
  `draftDenial`/`finaliseDenial`, the latter passing through the guard).
- `POST /api/gdpr/cases/{id}/evidence` → trigger/attach evidence harvest.
- `POST /api/gdpr/cases/{id}/redactions` → apply a field-level redaction.
- `POST /api/gdpr/cases/{id}/bundle` → generate the signed export bundle (returns a one-time
  download reference).
- `GET  /api/gdpr/cases/{id}/bundle/download?token=YOUR_TOKEN_HERE` → one-time secure download
  (token burned on use).

All carry `@NoAdminRequired` (+ `@NoCSRFRequired` where the client cannot supply a CSRF token, e.g.
the download), RBAC + case-level access control enforced in the service, and every method is
registered in `appinfo/routes.php` (ADR-016, ADR-029). None is `@PublicPage`.

## Risks / Trade-offs

- **[Depends on the head's register being imported first]** → The guard FQCN, evidence/redaction
  shapes, and retention windows come from `dsar-case-subsystem`. Mitigation: `depends_on`
  ordering — Hydra will not build this until the head's issue is merged; the guard is referenced by
  the head's transition and resolved at runtime (same way decidesk's `MeetingTransitionGuard`
  reference resolves).
- **[PAdES-LTV signing is a genuinely new dependency in OR]** → No existing PAdES/secure-token
  primitive was found under `lib/`. **Decision: full PAdES-LTV signing is REQUIRED in Phase 1**
  (not deferred). Selecting + vendoring the PAdES-LTV signing library is therefore an in-scope
  Phase-1 task (see tasks §2.1). Mitigation for the new dependency: isolate signing behind the
  export-bundle service so the library is a single, swappable dependency; the SHA-256 content hash
  is an additional integrity guarantee carried alongside the signature, not a fallback for it.
- **[One-time download token could leak or be replayed]** → Mitigation: mint a
  single-use, time-boxed token that is burned on first successful download; the token is never a
  realistic-looking secret in any artifact (`YOUR_TOKEN_HERE` placeholder only); the download route
  is authenticated + case-scoped, not `@PublicPage`.
- **[Retention sweep hard-deletes irreversibly]** → Mitigation: legal-hold-aware
  (`hasActiveLegalHold`) + an `IAppConfig` dry-run toggle mirroring `AvgRetentionJob`, so the first
  deployment can log what *would* be destroyed before acting; every sweep action is audited.
- **[Evidence harvest reaching external systems can be slow / partial]** → Mitigation: async
  collection with per-item `status` so a slow/failed source is visible on the case and re-runnable;
  content-hash dedup makes re-runs idempotent (no duplicate evidence).
- **[Case-level access control silently failing open]** → Mitigation: the check fails closed; a
  resolver that cannot determine the officer role denies rather than skips (CWE-863 / OWASP A01).

## Migration Plan

Additive only: new interface + registry, new services, a new `TimedJob`, new controller(s), new
routes. **No new OR schema** — this change consumes the head's register. No data backfill; existing
cases (seeded by the head) simply gain runnable behaviour. Rollback is removing the new classes +
routes + the job registration; the head's declarative case remains valid and exercisable via OR's
existing object/lifecycle/aggregation APIs. The retention sweep ships with its enabled toggle
defaulting on and a dry-run toggle for first-deployment verification (per `AvgRetentionJob`).

## Seed Data

This change adds **no new OR schemas** — it consumes the `dataSubjectRequest` schema + register the
`dsar-case-subsystem` head already extended (and whose seed case objects — municipality,
consultancy, travel agency — that head already provides). Therefore there is **no seed-data task
here**. Tests run the CI way (php:8.3-cli + OCP stubs, no live Nextcloud) against `ObjectService` /
provider / `RetentionService` doubles; any fixture ids/tokens use safe placeholders only (nil UUID
`00000000-0000-0000-0000-000000000000`, `YOUR_TOKEN_HERE`) — never realistic secrets/UUIDs.

## Resolved decisions

- **Bundle format + signing** — the export bundle is a **PDF disclosure document** (art-15 access);
  full **PAdES-LTV signing is REQUIRED in Phase 1** (not deferred to a SHA-256-only interim), which
  is why a PDF/PAdES signer is the dependency. Selecting + vendoring the PAdES-LTV signing library is
  an in-scope Phase-1 task; signing is isolated behind the export-bundle service as a single swappable
  dependency, with a SHA-256 content hash carried alongside the signature. A machine-readable art-20
  *portability* payload (JSON/CSV + JAdES/CAdES) is a deferred follow-up, out of scope here.
- **Route namespace** — case-management endpoints live under **`/api/gdpr/cases/...`**, mirroring the
  existing DSAR routes.
- **Officer-override access control** — resolved from an admin-configured **ADR-023 action/group
  mapping now**; the check **fails closed** when the role is unresolved. Phase-2 policy data may
  refine the mapping.

## Open Questions

- One-time download token lifetime + concrete storage (short-lived app-config-backed vs a dedicated
  token store) — deferred to implementation; the security posture (single-use, burned on first use,
  time-boxed, authenticated, case-scoped) is fixed in the spec regardless.
