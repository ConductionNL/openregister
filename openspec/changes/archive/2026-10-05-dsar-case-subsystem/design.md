## Context

OpenRegister ships a **stateless** DSAR engine today. `DataSubjectRequestService`
(`lib/Service/Gdpr/DataSubjectRequestService.php`) fulfils art-15/16/17/18/20/21 on demand
(discover, access-export, rectify, erase, restrict, object) under RBAC + tenant scoping, pinning
every write to the DSAR processing activity so the immutable hash-chained `AuditTrailMapper`
records it (`ObjectEntity::setProcessingActivityId()`). `DataSubjectDeadline`
(`lib/Service/Gdpr/DataSubjectDeadline.php`) is the pure art-12(3) maths
(`computeDueAt`/`extend`/`isOverdue`/`daysRemaining`). `lib/Settings/data_subject_request_register.json`
already declares a `dataSubjectRequest` schema with an `x-openregister-lifecycle`
(`received → verifying → in-progress → fulfilled/refused/closed`) and the art-12 fields
(`receivedAt`/`dueAt`/`extendedUntil`). Controllers `DataSubjectRequestController` and
`DsarController` expose the fulfilment surface.

What is absent is the **stateful case**: the request is a first-class tracked object that a
handler owns, that escalates as its deadline nears, that collects evidence, that can be denied
under a recorded ground with a regulator reference, that redacts fields with a before/after audit,
that produces a signed export bundle, and that is retained under a configured window. ADR-047
assigns this DSAR *case workflow* to OpenRegister as a register/schema-scoped capability. Per
ADR-031, the case entity, its lifecycle, its deadline-tracking aggregations, and its
reminder/escalation/breach notifications are **schema-declarative config**; only document
generation, external providers, and scheduled bulk work stay imperative.

This change is the **declarative head** of the Phase-1 chain: it extends the register JSON only.
The imperative surface is the successor `dsar-case-engine` change.

## Goals / Non-Goals

**Goals:**
- Extend the persisted `dataSubjectRequest` case entity in the register JSON with the
  case-management fields it lacks (handler, `closedAt`, retention stamps, DPIA flag, denial fields,
  evidence + redaction sub-collections), each with a human-friendly `title`/`description` (ADR-011).
- Extend `x-openregister-lifecycle` into a configurable N-state case graph with the case
  transitions and a mandatory `regulatorReference` guard before `finaliseDenial`.
- Declare deadline **tracking** on top of the existing art-12 maths via
  `x-openregister-calculations` (`daysRemaining`, `isOverdue`, `escalationTier`),
  `x-openregister-aggregations` (open/overdue/breached counts), and
  `x-openregister-notifications` (advance-reminder, escalation, breach), with fire-once idempotent
  semantics from the notification engine.
- Declare the denial-ground enum (generic keys) and the redaction sub-collection shape.
- Declare windowed retention stamps (`retentionWindow`/`retainUntil`/`purgedAt`) as config.
- Ship realistic seed case objects (municipality, consultancy, travel agency) with safe
  placeholders.

**Non-Goals:**
- No PHP service classes, controllers, or routes in this change — that is `dsar-case-engine`
  (kind:code, `depends_on` this change): pluggable evidence providers, bundle signing/PAdES,
  one-time secure download, the redaction-audit write path, regulator dossier assembly, the
  retention sweep job, and the case-level access-control layer.
- No jurisdiction policy-pack contract and no identity-verify (BSN/BRP) / regulator-escalate (AP)
  integration seams — that is Phase 2 `dsar-policy-pack-and-seams`, referenced only as `depends_on`
  successor context.
- No change to the existing stateless fulfilment behaviour — it is reused verbatim.

## Decisions

### Declarative-vs-imperative decision (ADR-031)

Default is declarative (schema register); each imperative exception is justified below. This
change authors **only the declarative column**; the imperative column is scoped into
`dsar-case-engine` and listed here so the reviewer sees the whole picture.

| Behaviour | Chosen path | Rationale |
|---|---|---|
| **Case lifecycle / state machine** (N-state, handler assignment, denial-draft/finalise, redact/bundle/retain transitions) | **Declarative** — `x-openregister-lifecycle` on `dataSubjectRequest` | A state machine over one object's `status` field is the canonical `x-openregister-lifecycle` fit. Gives audit-trailed transitions, RBAC per state, replayability, CloudEvents, MCP discovery for free. Zero PHP. |
| **Deadline-tracking aggregation** (open/overdue/breached counts; `daysRemaining`/`isOverdue`/`escalationTier` derived fields) | **Declarative** — `x-openregister-aggregations` + `x-openregister-calculations` referencing `dueAt`/`extendedUntil` | The art-12 arithmetic already exists in `DataSubjectDeadline`; *tracking* is derived state + cross-object counts, exactly what calculations/aggregations materialise. No per-app aggregation service (ADR-031 anti-pattern). |
| **Breach / reminder / escalation notifications** | **Declarative** — `x-openregister-notifications` (canonical ADR-031 dialect; `scheduled` + `threshold`/`calculatedChange` triggers) | Notification-on-condition is the notification dialect's job; the engine's fire-once-per-condition semantics give the "idempotent deadline-event audit" the ADR-047 table asks for. Hand-rolling a `NotificationService` is a gate-18 fail. |
| **Retention windows** (which window, `retainUntil`/`purgedAt` stamps) | **Declarative (config)** for the window selection + stamps | Windows are config values on the case object; the stamps are declared fields. Only the *sweep* is imperative. |
| **Retention sweep** (hard-delete dossier + scrub evidence PII on a schedule) | **Imperative — deferred to `dsar-case-engine`** | Scheduled bulk work over an object queue with side effects (delete/scrub) is the ADR-031 exception: use a `ScheduledWorkflow`/job, not a declarative construct. Reuses `RetentionService` legal-hold awareness. |
| **Evidence dedup** (pluggable source providers, dedup by content hash, per-item status) | **Imperative — deferred to `dsar-case-engine`** | External-source harvest via provider adapters is exactly ADR-003/ADR-031 "external API integration"; the schema engine can't reach outside systems. The *evidence sub-collection shape* is declared here; the harvesting is code there. |
| **Denial gate** (config-driven ground enum + mandatory regulator-reference before finalise) | **Declarative** — enum property + `x-openregister-lifecycle` transition guard `requires` | The enum is schema config; the "regulatorReference must be set" precondition is a short lifecycle guard (`requires: FQCN`) — the legitimate PHP seam ADR-031 §3 allows, authored in `dsar-case-engine` and *referenced* by the declarative transition here. |
| **Bundle signing** (assemble + PAdES sign + one-time secure download + regulator dossier) | **Imperative — deferred to `dsar-case-engine`** | Document generation / PAdES signing / rendered output is the ADR-031 document-generation exception; the schema engine has no opinion on rendered bytes. |
| **Redaction audit** (before/after + ground, distinct from erase pseudonymise) | **Split** — sub-collection *shape* declarative here; *write path* imperative in `dsar-case-engine` | The redaction record is a declared field; capturing before/after at redaction time is a write operation that belongs to the engine change. |

### Reuse before build (ADR-011)

Searched `lib/Formats/`, `lib/Service/`, `lib/Service/Gdpr/`, `lib/Handler/` before proposing
anything. Reuse, do not duplicate:

- **`DataSubjectDeadline`** (`computeDueAt`/`extend`/`isOverdue`/`daysRemaining`) — the art-12
  maths the `escalationTier`/`daysRemaining` calculations reference. No new date helper.
- **`DataSubjectRequestService`** — the erase/rectify/restrict/object fulfilment the case
  transitions drive; the case does not re-implement fulfilment.
- **`AuditTrailMapper` + hash chain + `ObjectEntity::setProcessingActivityId()`** — every case
  transition and field change is audited through the existing immutable trail; no parallel audit.
- **`RetentionService`** (`hasActiveLegalHold`/`validateNotImmutable`) — the retention sweep
  (engine change) reuses legal-hold awareness; erase already honours it.
- **`BsnFormat`** (`lib/Formats/BsnFormat.php`) — reserved for Phase-2 identity verification; not
  used here, noted so the Phase-2 author reuses it.
- **`DataSubjectRequestController` / `DsarController`** — the existing fulfilment routes; the
  case-management endpoints (engine change) extend the surface rather than replace it.

### Object / schema service patterns (ADR-001, ADR-003)

All case objects are OR objects under the `data-subject-requests` register / `dataSubjectRequest`
schema. Reads/writes flow through `ObjectService` (RBAC + multitenancy). `SchemaService` /
`RegisterService` own the schema definition loaded from the register JSON. No custom
Entity/Mapper for the case (ADR-001). The lifecycle/aggregation/calculation/notification engines
consume the `x-openregister-*` blocks folded into the schema `configuration` at import — the same
path `decidesk_register.json` uses.

### Lifecycle graph shape

The existing graph is preserved (initial `received`; final `fulfilled`/`refused`/`closed`) and
extended with case-management transitions layered on the intermediate states:
`assign` (set handler), `collectEvidence`, `draftDenial` → `finaliseDenial` (guarded by
`regulatorReference` presence), `redact`, `bundle`, `retain`. The state *set* is register config
so a jurisdiction/tenant can add or rename states in Phase-2 policy data without a code change.

## Risks / Trade-offs

- **[Declarative head with no runnable surface]** — this change alone extends the schema but the
  imperative behaviours (evidence harvest, signing, sweep, redaction write, controllers) don't
  exist until `dsar-case-engine`. Mitigation: the two changes are a chain; `dsar-case-engine`
  `depends_on` this and lands the surface. The declarative case is still exercisable via OR's
  existing object/lifecycle/aggregation APIs the moment it imports.
- **[Lifecycle-guard reference to a not-yet-authored FQCN]** — the `finaliseDenial` guard
  references a PHP guard class delivered by `dsar-case-engine`. Mitigation: declare the transition
  with the `requires` reference; the guard class is a task in the engine change; until it lands the
  transition is declared but the register import tolerates a pending guard the same way decidesk's
  `MeetingTransitionGuard` reference is resolved at runtime.
- **[Notification fire-once semantics must be idempotent]** — advance-reminder/escalation/breach
  must not re-fire each scheduler tick. Mitigation: rely on the ADR-031 engine's
  fire-once-per-condition + user-override preference model rather than a hand-rolled idempotency
  key (the legacy `idempotencyKey` dialect is obsolete/rejected by gate-18).
- **[Seed data leaking realistic PII]** — seed cases carry subject identifiers. Mitigation: use
  obvious placeholder emails/ids and the nil UUID; never realistic-looking secrets or UUIDs
  (gitleaks-flagged).
- **[Schema field addition is a migration]** — adding required/typed fields to a live schema.
  Mitigation: all new fields are **optional** (non-breaking per ADR-011 versioning); no existing
  required field is removed/renamed, so no repair-step migration is needed — the register import
  UNION-merges additively.

## Seed Data

Realistic `dataSubjectRequest` case objects for the `data-subject-requests` register /
`dataSubjectRequest` schema, using general org data and **safe placeholders** (nil UUID
`00000000-0000-0000-0000-000000000000`, `YOUR_TOKEN_HERE`, `<client-uuid>`). Seeded via the OR
seed path (schemas gain fields → a seed task is required, ADR-001).

**Case 1 — Municipality (access request, on track):**
```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "subjectId": "j.jansen@example.org",
  "subjectType": "email",
  "type": "access",
  "status": "in-progress",
  "handler": "handler-gemeente",
  "receivedAt": "2026-06-01T09:00:00+00:00",
  "dueAt": "2026-07-01T09:00:00+00:00",
  "closedAt": null,
  "dpiaRequired": false,
  "retentionWindow": "standard",
  "retainUntil": "2027-07-01T00:00:00+00:00",
  "evidence": [
    { "sourceId": "or-objects", "contentHash": "sha256:PLACEHOLDER_HASH", "status": "collected" }
  ],
  "notes": "Inwoner vraagt inzage in geregistreerde persoonsgegevens."
}
```

**Case 2 — Consultancy (erasure, deadline extended):**
```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "subjectId": "contact@example-consultancy.test",
  "subjectType": "email",
  "type": "erasure",
  "status": "verifying",
  "handler": "handler-privacy",
  "receivedAt": "2026-05-20T12:00:00+00:00",
  "dueAt": "2026-06-20T12:00:00+00:00",
  "extendedUntil": "2026-08-20T12:00:00+00:00",
  "extensionReason": "Complex cross-register discovery; single art-12(3) extension applied.",
  "dpiaRequired": true,
  "retentionWindow": "extended"
}
```

**Case 3 — Travel agency (objection, denied with regulator reference):**
```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "subjectId": "traveler@example-travel.test",
  "subjectType": "email",
  "type": "objection",
  "status": "refused",
  "handler": "handler-dpo",
  "receivedAt": "2026-04-10T08:30:00+00:00",
  "dueAt": "2026-05-10T08:30:00+00:00",
  "closedAt": "2026-05-08T16:00:00+00:00",
  "denialGround": "overriding-legitimate-grounds",
  "regulatorReference": "REG-REF-PLACEHOLDER-0001",
  "outcome": "Objection refused; compelling legitimate grounds documented; regulator reference recorded.",
  "redactions": [
    { "field": "notes", "before": "[redacted-source]", "after": "[erased]", "ground": "third-party-data" }
  ]
}
```

## Migration Plan

Additive only. All new properties are **optional** (ADR-011: adding optional properties is
non-breaking); no property is removed or renamed, so no repair-step migration is required. The
register import UNION-merges the extended schema definition. Rollback is reverting the register
JSON. The seed objects are illustrative and can be re-seeded or removed independently.

## Open Questions

- Exact escalation-tier boundaries (e.g. reminder at T-7d, escalation at T-2d, breach at T+0) —
  provisional in this change; final thresholds are candidate Phase-2 policy-pack values.
- Whether the denial-ground enum keys stay generic here and gain jurisdiction wording only in
  Phase-2 policy data (recommended) vs a fuller generic set now.
- Whether the `finaliseDenial` guard is a lifecycle `requires` FQCN (recommended, ADR-031 §3) or a
  purely declarative "required field before transition" the lifecycle engine can express natively —
  depends on current `x-openregister-lifecycle` guard expressiveness; deferred to the engine change.
