# Tasks — dsar-case-subsystem (kind: config)

This change is the declarative head of the ADR-047 Phase-1 chain: it patches the register JSON
only. No PHP services, controllers, or routes — those land in the successor `dsar-case-engine`.

## 1. Case entity (schema properties)

- [x] 1.1 Add case-management properties to `dataSubjectRequest` in `lib/Settings/data_subject_request_register.json`: `handler`, `closedAt`, `dpiaRequired`, retention stamps (`retentionWindow`, `retainUntil`, `purgedAt`) — all optional, each with a human-friendly `title` + `description` (ADR-011).
- [x] 1.2 Add the denial-workflow properties `denialGround` (config-driven generic-key enum, no jurisdiction wording) and `regulatorReference` (optional), with `title`/`description`.
- [x] 1.3 Add the `evidence` sub-collection (items carry `sourceId`, `contentHash`, `status`) and the `redactions` sub-collection (entries carry `field`, `before`, `after`, `ground`) as declared array properties with nested `title`/`description` on every item property.

## 2. Case lifecycle (declarative)

- [x] 2.1 Extend `x-openregister-lifecycle` on `dataSubjectRequest` with the N-state case transitions (`assign`, `collectEvidence`, `draftDenial`, `finaliseDenial`, `redact`, `bundle`, `retain`), preserving initial `received` and finals `fulfilled`/`refused`/`closed`.
- [x] 2.2 Declare the `finaliseDenial` transition guard requiring `regulatorReference` presence (lifecycle `requires` reference to the guard delivered by `dsar-case-engine`, or a native required-field precondition if the engine supports it) — `draftDenial` MUST NOT require the reference.

## 3. Deadline tracking (declarative)

- [x] 3.1 Add `x-openregister-calculations` for `daysRemaining`, `isOverdue`, `escalationTier`, computed against the effective deadline (`extendedUntil` else `dueAt`), reusing `DataSubjectDeadline` semantics (ADR-011 — no second deadline implementation).
- [x] 3.2 Add `x-openregister-aggregations` for open / overdue / breached case counts over the RBAC + tenant-scoped set.
- [x] 3.3 Add `x-openregister-notifications` (canonical ADR-031 dialect; `scheduled`/`threshold`/`calculatedChange` triggers) for advance-reminder, escalation, and breach rules targeting the case `handler`, with fire-once-per-condition (idempotent) semantics — no legacy dialect fields (gate-18).

## 4. Seed data

- [x] 4.1 Add seed `dataSubjectRequest` case objects for a municipality (access, on-track), a consultancy (erasure, extended), and a travel agency (objection, denied with regulator reference), using safe placeholders (nil UUID `00000000-0000-0000-0000-000000000000`, `YOUR_TOKEN_HERE`, `<client-uuid>`) — per the design Seed Data section.

## 5. Verification

- [x] 5.1 Validate `lib/Settings/data_subject_request_register.json` parses and imports cleanly (schema + `x-openregister-*` blocks fold into `configuration`); confirm the schema extension is additive (no existing required property removed/renamed).
- [x] 5.2 Run the Hydra mechanical gates relevant to config (`notification-dialect` gate-18, `schema-property-titles` gate-28) and `openspec validate --change dsar-case-subsystem --strict`; fix any pre-existing issues touched.

## Acceptance Criteria

- The `dataSubjectRequest` schema gains handler, `closedAt`, retention stamps, DPIA flag, denial fields, and evidence/redaction sub-collections — all optional, all human-labelled.
- The status graph is a configurable N-state `x-openregister-lifecycle`; initial/final semantics preserved; `finaliseDenial` blocked until `regulatorReference` is set, `draftDenial` not blocked.
- Deadline tracking exposes `daysRemaining`/`isOverdue`/`escalationTier` calculations and open/overdue/breached aggregations, RBAC + tenant scoped, reusing `DataSubjectDeadline`.
- Advance-reminder / escalation / breach notifications use the canonical dialect and fire once per condition per case (idempotent).
- Seed cases exist for the three orgs with only safe placeholder ids/tokens.
- No PHP service/controller/route is added by this change; the schema extension is additive and non-breaking.

## Quality Checklist

- Reused `DataSubjectDeadline` maths and the existing `DataSubjectRequestService` / audit-trail / `RetentionService` rather than duplicating them (ADR-011, ADR-022).
- Declarative-first: lifecycle, calculations, aggregations, and notifications are register config, not new Service classes (ADR-031); imperative bits are correctly deferred to `dsar-case-engine`.
- Canonical `x-openregister-notifications` dialect only; no obsolete legacy notification fields (gate-18).
- Every added property (including nested sub-collection item properties) declares `title` + `description` (ADR-011, gate-28).
- Seed and example ids/tokens use obvious placeholders (nil UUID, `YOUR_TOKEN_HERE`, `<client-uuid>`) — no realistic-looking secrets/UUIDs (gitleaks).
- Schema extension is additive (optional properties only) — non-breaking, no repair-step migration required (ADR-011 versioning).
