---
kind: code
depends_on: [dsar-case-subsystem]
---

## Why

The declarative head `dsar-case-subsystem` (kind:config) extends
`lib/Settings/data_subject_request_register.json` with the stateful `dataSubjectRequest` case
entity, its N-state `x-openregister-lifecycle`, deadline-tracking `-calculations`/`-aggregations`,
and reminder/escalation/breach `-notifications`. It deliberately declared only the **shape** of the
evidence and redaction sub-collections and stamped retention windows — it does **not** ship the
imperative surface those declarations imply. Per ADR-031 that surface is genuinely code: external
evidence harvest, PAdES document signing, scheduled retention bulk-work, and a lifecycle guard.

This change is the **imperative successor** in the ADR-047 Phase-1 chain (config head →
**this engine** → Phase 2 `dsar-policy-pack-and-seams`). It builds the runnable case-management
behaviour on top of the head's declarations and OpenRegister's existing stateless DSAR primitives
(`DataSubjectRequestService`, `DataSubjectDeadline`, `RetentionService`, `AuditTrailMapper`), so
that the moment both changes land a register gets a fully operational, tracked data-subject-request
workflow — not just a schema. It lists `dsar-case-subsystem` in `depends_on`: the case entity,
lifecycle graph, denial-ground enum, and notification rules must already exist for this change's
providers, guard, sweep, and controllers to bind to them.

## What Changes

- **Pluggable evidence-source providers** — an `EvidenceSourceProvider` interface + a registry so
  leaf apps register harvest sources (OpenConnector et al.), plus async collection that dedups
  items by content hash and tracks per-item collection `status` on the case's declared `evidence`
  sub-collection. (ADR-019 registry style; ADR-003 external-integration.)
- **Signed export bundle** — assemble the subject's data by reusing
  `DataSubjectRequestService::assembleAccessExport`, sign it (PAdES-LTV / SHA-256), mint a
  one-time secure download token, and assemble a regulator dossier from the case's evidence,
  redactions, and audit trail.
- **Field-level redaction write path** — apply a redaction (before/after + ground) to a case
  field and record the `redactions` entry through the immutable audit trail. Distinct from the
  erase-time pseudonymise that `DataSubjectRequestService::erase(mode=pseudonymise)` already does.
- **Denial finalise guard** — the lifecycle-transition guard class the head's `finaliseDenial`
  transition references via `requires`; it enforces the mandatory `regulatorReference` before a
  case may enter the finalised-denial outcome (ADR-031 §3 legitimate PHP seam).
- **Retention sweep** — a scheduled `TimedJob` that enforces the head's declared windowed
  retention: hard-delete dossiers past their `retainUntil`, scrub evidence PII via
  `DataSubjectRequestService::erase(mode=pseudonymise)`, legal-hold aware via
  `RetentionService::hasActiveLegalHold`. (ADR-031 scheduled-bulk-work exception; reuses the
  existing `AvgRetentionJob`/`TimedJob` pattern.)
- **Controllers + routes** — case-management REST endpoints (create case, transition, attach
  evidence, generate bundle, download bundle, deny/finalise) registered in `appinfo/routes.php`
  with the correct auth posture (docblock `@NoAdminRequired` + RBAC in the service, ADR-005/016),
  plus a case-level access-control check layered on OR object RBAC (handler-scopes-own + officer
  override, ADR-023).

**Explicitly out of scope — Phase 2 `dsar-policy-pack-and-seams` (successor; not specced here):**
the jurisdiction policy-pack config contract (deadline durations, denial-grounds wording,
retention-window values, letter templates) and the two pluggable integration seams —
identity-verify (BSN/BRP/RvIG) and regulator-escalate (AP-complaint dossier). This change defines
the generic case engine; Phase 2 supplies the jurisdiction data + bindings it consumes. Referenced
as `depends_on` successor context only.

## Capabilities

### New Capabilities
- `dsar-evidence-collection`: the pluggable evidence-source provider interface + registry and the
  async, content-hash-deduplicated harvest that populates the case's `evidence` sub-collection with
  per-item status.
- `dsar-export-bundle`: assembly + PAdES/SHA-256 signing of the export bundle, the one-time secure
  download token, and regulator-dossier assembly.
- `dsar-redaction-write`: the field-level redaction write path recording before/after + ground to
  the audit trail, distinct from erase-time pseudonymise.
- `dsar-denial-guard`: the `finaliseDenial` lifecycle-transition guard enforcing the mandatory
  `regulatorReference` precondition.
- `dsar-retention-sweep`: the scheduled, legal-hold-aware retention sweep (dossier hard-delete +
  evidence-PII scrub) enforcing the head's declared windows.
- `dsar-case-api`: the case-management REST controllers/routes and the case-level access-control
  layer (auth posture + handler-scopes-own + officer override) over OR object RBAC.

### Modified Capabilities
<!-- None. This change CONSUMES the dsar-case-subsystem register (case entity, lifecycle,
     denial-ground enum, notification rules) and OR's existing stateless DSAR primitives. It adds
     no requirement to, and does not alter, those capabilities — it references and binds to them. -->

## Impact

- **New code**: an `EvidenceSourceProvider` interface + registry and the harvest service; an
  export-bundle service (assemble/sign/tokenise/dossier); a redaction-write service; the
  `finaliseDenial` guard class; a retention-sweep `TimedJob`; case-management controller(s); a
  case-level access-control check; new route entries in `appinfo/routes.php`.
- **Consumes (unchanged)**: `DataSubjectRequestService` (`assembleAccessExport`, `erase`),
  `DataSubjectDeadline`, `RetentionService` (`hasActiveLegalHold`, legal-hold awareness),
  `AuditTrailMapper` + hash chain, `ObjectEntity::setProcessingActivityId()`, `ObjectService`
  (RBAC + multitenancy), the `AvgRetentionJob`/`TimedJob` scheduled-work pattern, and (reserved for
  Phase 2 identity) `BsnFormat`.
- **Consumes (from `dsar-case-subsystem`)**: the extended `dataSubjectRequest` schema + register,
  the `x-openregister-lifecycle` graph (incl. the `finaliseDenial` transition that references this
  change's guard), the `denialGround` enum, the `evidence`/`redactions` sub-collection shapes, the
  `retentionWindow`/`retainUntil` stamps, and the deadline-tracking notification rules.
- **APIs**: new authenticated case-management endpoints (create/transition/attach-evidence/
  generate-bundle/download/deny-finalise), each `@NoAdminRequired` with RBAC + case-level access
  control in the service; no breaking change to the existing `/api/gdpr/*` or `/api/avg/*` routes.
- **No new schemas** — this change consumes the head's register; it adds no OR schema.
- **Downstream**: Phase 2 `dsar-policy-pack-and-seams` depends on both this change and the head;
  pipelinq's `avg-consume-or-workflow` retirement is gated on the full chain. No app is migrated by
  this change.
