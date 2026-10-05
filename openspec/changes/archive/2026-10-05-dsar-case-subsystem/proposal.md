---
kind: config
depends_on: []
---

## Why

OpenRegister already fulfils data-subject rights **statelessly** — `DataSubjectRequestService`
(art-15/16/17/18/20/21 + `computeDueAt`/`extend`/`isOverdue`), the pure `DataSubjectDeadline`
art-12(3) maths, the hash-chained `AuditTrailMapper`, and a `dataSubjectRequest` schema with an
`x-openregister-lifecycle` already shipped in `lib/Settings/data_subject_request_register.json`.
What is missing is a persisted, **stateful case** on top of that engine: a tracked request with a
configurable status graph, handler assignment, deadline *tracking* (reminder/escalation/breach —
not just the art-12 arithmetic), evidence, denial, redaction audit, a signed export bundle, and
windowed retention. ADR-047 rules that this DSAR **case workflow** is a register/schema-scoped
OpenRegister capability, not a per-app rebuild — pipelinq is the pilot that today carries its own
9-state `avgVerzoek` workflow that this subsumes.

This change is the **head of the ADR-047 Phase-1 delivery chain**. Per ADR-031 the case entity,
its N-state lifecycle, deadline-tracking aggregations, and reminder/escalation/breach
notifications are **declarative schema-register config** (`x-openregister-{lifecycle,
aggregations, calculations, notifications}` on `dataSubjectRequest`), NOT new service classes. It
ships that declarative slice (`kind: config`) and hands the genuinely-imperative bits (pluggable
evidence providers, bundle signing/PAdES, one-time secure download, redaction-audit write path,
regulator dossier assembly, controllers) to the successor `dsar-case-engine` change, which lists
this change in its `depends_on`. This split keeps the head-of-chain change a single declarative
shape rather than a `mixed`-shaped anti-pattern (see DEFERRED_QUESTIONS — this split decision is
explicitly flagged for the human).

## What Changes

- **Extend the persisted `dataSubjectRequest` case entity** in
  `lib/Settings/data_subject_request_register.json`: add the case-management fields the current
  fulfilment-only schema lacks — `handler` (assignment), `receivedAt`/`dueAt`/`closedAt` case
  timestamps (dueAt already present; add `closedAt`), retention stamps
  (`retainUntil`/`retentionWindow`/`purgedAt`), a `dpiaRequired` complexity flag, denial-workflow
  fields (`denialGround`/`regulatorReference`), and evidence/redaction sub-collections. Every
  property carries a human-friendly `title` + `description` (ADR-011).
- **Make the status graph configurable and N-state** by extending the existing
  `x-openregister-lifecycle` block: add case-management states/transitions
  (`assign`, `collectEvidence`, `draftDenial`, `finaliseDenial`, `redact`, `bundle`, `retain`)
  layered on the current `received → verifying → in-progress → fulfilled/refused/closed` graph,
  keeping the initial/final semantics. The state set is register config, not hard-coded PHP.
- **Declare deadline TRACKING as aggregations + notifications**, on top of the existing art-12
  maths: `x-openregister-calculations` for `daysRemaining`/`isOverdue`/`escalationTier`
  (referencing `dueAt`/`extendedUntil`), `x-openregister-aggregations` for open/overdue/breached
  case counts, and `x-openregister-notifications` for advance-reminder, escalation, and
  breach-detection rules (the canonical ADR-031 notification dialect; `scheduled` + `threshold`
  triggers). Idempotent deadline-event audit is inherent to the notification engine's
  fire-once-per-condition semantics.
- **Declare the denial gate and redaction-audit shape** as schema config: `denialGround` is a
  config-driven enum (generic ground keys; jurisdiction wording is Phase 2 policy-pack data), and
  a lifecycle transition guard requires `regulatorReference` to be set before `finaliseDenial`.
  Redaction entries (before/after + ground) are a declared sub-collection distinct from the
  erase-time pseudonymise the engine already does.
- **Windowed retention as config**: `retentionWindow` selects a window; the retention stamps are
  written declaratively; the actual hard-delete/scrub sweep is deferred to `dsar-case-engine`
  (imperative scheduled work, ADR-031 external-orchestrator exception).
- **Seed data**: realistic `dataSubjectRequest` example objects (municipality, consultancy,
  travel agency) using safe placeholder ids, added as a seed task since the schema gains fields.

**Explicitly out of scope — successor `dsar-case-engine` change (kind:code, `depends_on` this):**
pluggable evidence source providers (dedup by content hash, per-item status); signed export
bundle assembly + PAdES signing + one-time secure download; the redaction-audit *write* path;
regulator dossier assembly; the retention sweep job; the case-level access-control layer and
controllers/routes. **Out of scope — Phase 2 `dsar-policy-pack-and-seams`:** the jurisdiction
policy-pack config contract, and the identity-verify (BSN/BRP) + regulator-escalate (AP)
integration seams. Phase 2 is `depends_on` successor context only; not specced here.

## Capabilities

### New Capabilities
- `dsar-case-entity`: the persisted, stateful `dataSubjectRequest` case entity — handler
  assignment, case timestamps, retention stamps, DPIA flag, denial fields, evidence/redaction
  sub-collections — declared as schema properties on the existing register.
- `dsar-case-lifecycle`: the configurable N-state case status graph and its transition guards
  (including the mandatory `regulatorReference` gate before `finaliseDenial`), declared via
  `x-openregister-lifecycle` on `dataSubjectRequest`.
- `dsar-deadline-tracking`: advance-reminder, escalation, and breach-detection tracking on top of
  the existing art-12 deadline maths, declared via `x-openregister-{calculations,aggregations,
  notifications}` — with fire-once (idempotent) deadline-event semantics.

### Modified Capabilities
- `gdpr-data-subject-rights`: the existing stateless capability gains a **stateful case** overlay.
  Its requirements do not change; this change adds new requirements in the three new capabilities
  above and references the stateless engine as reused. (No delta spec — behaviour is additive, no
  existing requirement is altered.)

## Impact

- **Config (this change)**: `lib/Settings/data_subject_request_register.json` — extended schema
  properties, `x-openregister-lifecycle`, `-calculations`, `-aggregations`, `-notifications`
  blocks on `dataSubjectRequest`; a seed file/task for the example case objects.
- **Reused, unchanged**: `lib/Service/Gdpr/DataSubjectRequestService.php`,
  `lib/Service/Gdpr/DataSubjectDeadline.php`, `AuditTrailMapper` + hash chain,
  `ObjectEntity::setProcessingActivityId()`, `lib/Controller/DataSubjectRequestController.php` /
  `DsarController.php`, `RetentionService` legal-hold awareness, `BsnFormat` (Phase-2 identity).
- **APIs**: no new routes in this change — the declarative case is served by OR's existing
  object/lifecycle/aggregation APIs. Controllers/routes for the imperative surface land in
  `dsar-case-engine`.
- **Downstream**: `dsar-case-engine` (kind:code) depends on this; Phase 2
  `dsar-policy-pack-and-seams` depends on both; pipelinq's `avg-consume-or-workflow` retirement is
  gated on the full chain. No app is migrated by this change.
