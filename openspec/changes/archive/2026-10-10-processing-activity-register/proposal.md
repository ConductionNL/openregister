# Processing Activity Register — Platform AVG/GDPR Verwerkingsregister & Verwerkingenlogging

## Why

On 2026-06-11 three apps independently authored near-identical AVG/GDPR
processing-register changes on the same day:

- **procest** `avg-verwerkingenlogging` — VNG Logging Verwerkingen:
  per-access append-only log **including reads**, a
  verwerkingsactiviteitenregister with doel/rechtsgrond/ontvangers, FG
  inquiry + inzageverzoek export, and a bearer-gated VNG-shaped API.
- **docudesk** `processing-activity-export` — Art. 30 aggregate export
  per activity category from existing OR data, JSON/CSV/PDF, controller
  identity header, hard no-literal-PII contract.
- **scholiq** `avg-verwerkingsregister` — Art. 30(1)-complete
  `ProcessingActivity` schema, seeded catalogue of the app's own
  processing, audit-trail-backed versioning, Art. 30(4) CSV export.

Three apps converging on the same requirement in one day is the proof
that the requirement is **abstract**: per ADR-022
(apps-consume-OR-abstractions), the storage, logging, export, and API
layers belong in OpenRegister as a platform capability. Every other
fleet app processing personal data (zaakafhandelapp, pipelinq,
shillinq, larpingapp, launchpad, …) has the identical legal obligation
and would otherwise author change number four, five, and six.

OpenRegister already owns half the machinery — verified against the
codebase (2026-06-11):

- `lib/Db/Verwerkingsactiviteit.php` + `VerwerkingsactiviteitMapper`
  (`findByCode()`, `resolveReference()`) + a CRUD
  `VerwerkingsactiviteitenController` — a platform processing-activity
  entity carrying most Art. 30(1) fields (naam, doelbinding,
  rechtsgrond, categorieën betrokkenen/persoonsgegevens, ontvangers,
  doorgifteBuitenEu, bewaartermijn, technische/organisatorische
  maatregelen, verwerkingsverantwoordelijke, contactgegevensFg,
  organisationId, status).
- `AuditTrailMapper::resolveProcessingActivityId()` already reads the
  `x-openregister-processing-activity` annotation (schema-level,
  register-level inheritance) and stamps `processingActivityId` onto
  every **write** audit-trail entry; `DsarService` attributes DSAR
  writes the same way.
- `AvgComplianceService::findUnannotatedSchemasWithPii()` surfaces
  schemas with detected PII but no processing-activity annotation.
- The hash-chained immutable audit trail (`audit-trail-immutable`)
  covers create/update/delete.

What is missing — exactly the union of what the three apps asked for:

1. **Reads are not logged.** The object audit trail covers mutations;
   the VNG Logging Verwerkingen standard and AVG art. 5(2)/30 demand a
   record of *every* processing, including raadplegen. `SearchTrail`
   logs queries but carries no activity/purpose/legal-basis context.
2. **No declarative app contribution.** The only hook is a single
   string annotation pointing at one pre-existing activity. Apps cannot
   ship a catalogue of their own processing activities, per-operation
   attribution, or a read-logging opt-in the way they ship notification
   rules via `x-openregister-notifications` (ADR-031).
3. **No lifecycle/validation on activities** (Art. 6 enum, Art. 9
   special-category basis, draft → active → retired, audit-trail-backed
   versioning of active entries, review cycle).
4. **No exports or API**: no org-level Art. 30 export (JSON/CSV/PDF),
   no per-subject (betrokkene) extract for an inzageverzoek, no VNG
   Logging Verwerkingen-shaped API.
5. **No scoping**: nothing lets each app's FG view show its own slice.

## What Changes

- **Verwerkingsactiviteitenregister hardening.** The platform
  `Verwerkingsactiviteit` entity becomes Art. 30(1)-complete (adds
  special-category flag + Art. 9 basis, legitimate-interest assessment,
  confidentiality flag, lifecycle `draft → active → retired`, owner +
  review cycle fields) with save-time validation (Art. 6 enum, 422 +
  structured error) and audit-trail-backed versioning of `active`
  entries.
- **Declarative app contribution: `x-openregister-processing`.** A
  register/schema-level annotation in the style of
  `x-openregister-notifications`: apps declare (a) a catalogue of their
  own processing activities (seeded as `draft` on register import,
  upserted by `code`), (b) attribution — which activity justifies
  operations on a schema, with per-operation overrides, and (c)
  `logReads: true` opt-in. The legacy string
  `x-openregister-processing-activity` keeps working as shorthand. The
  imperative API (`VerwerkingsactiviteitenController`,
  `ObjectEntity::setProcessingActivityId()`) remains as fallback.
- **Per-access processing log including reads.** Objects whose schema
  opts in produce an append-only `processingLogEntry` per read/export
  (writes stay on the audit trail — cross-referenced, not duplicated),
  capturing actor, activity, purpose, legal basis, channel, and
  processed-object identifiers (idType/idValue, e.g. BSN). Unattributed
  processing falls back to a seeded, **flagged** fallback activity
  ("Niet-geclassificeerde verwerking") so nothing is silently unlogged
  (procest's pattern). Emission is batched and asynchronous — never
  blocking the primary action — into aggregate-friendly storage, with
  configurable retention (default 3 years, VNG norm) and a prune job.
- **Export & API.** Org-level Art. 30 export (JSON/CSV/PDF, controller
  identity header, aggregate-only no-literal-PII contract), an
  FG/inzageverzoek per-subject extract (by betrokkene identifier +
  period, with purpose and legal basis per entry), and a bearer-gated
  VNG Logging Verwerkingen-shaped API.
- **Scoping.** Per-register/per-app filtering on every surface so each
  app's FG view shows its own slice; admin-only by default with
  delegation to a privacy-officer/FG group; multi-tenant
  (organisation-scoped) isolation throughout.
- Delta requirements on the existing `avg-verwerkingsregister`
  capability spec.

## Supersedes

This change **supersedes the storage, logging, API, and export layers**
of three per-app changes, which are being thinned to domain activity
catalogues (shipped via `x-openregister-processing`), app-specific UI
surfacing, and domain export inclusion:

| Per-app change | Superseded here | Stays in the app |
|---|---|---|
| `procest/openspec/changes/avg-verwerkingenlogging` | activity register, per-access log (incl. reads), append-only + retention, flagged fallback, FG query/export per betrokkene, VNG-shaped API | case-type → activity mapping + ZGW per-client attribution config, FG inquiry UI surfacing, procest's activity catalogue |
| `docudesk/openspec/changes/processing-activity-export` | Art. 30 aggregate export (JSON/CSV/PDF), controller-identity header, no-literal-PII contract, admin gating | docudesk's activity-category semantics + domain source aggregation, compliance settings/export UI, grondslagen-summary views |
| `scholiq/openspec/changes/avg-verwerkingsregister` | ProcessingActivity Art. 30(1) schema, seed-as-draft mechanics, audit-trail versioning, Art. 30(4) export, review-cycle reminders, RBAC | scholiq's seed catalogue content, Compliance navigation/UI surfacing, audit-pack ZIP inclusion of the export |

The requirement-by-requirement absorption map lives in `design.md`.

## Out of scope

- DSAR workflows (inzage/rectificatie/vergetelheid/portabiliteit) —
  already specced and shipped (`DsarController`/`DsarService`
  requirements in the main `avg-verwerkingsregister` spec); the
  per-subject extract here *feeds* an Art. 15 response, it does not
  re-specify the rights workflows.
- DPIA authoring, consent management, verwerker (Art. 28) registration,
  breach register — existing main-spec requirements or future changes;
  untouched.
- Purpose-bound access *control* (`requirePurposeBinding` /
  `PurposeBindingMiddleware`) — the main spec already requires it; this
  change is about *recording* processing, not blocking it.
- The three apps' domain UIs, their catalogue contents, and
  domain-specific export inclusion (e.g. scholiq audit-pack ZIP) — those
  remain in the thinned per-app changes.
- Logging of processing outside OpenRegister object storage (NC Files
  reads, Talk, mail) — OR can only attest to what flows through it.
- Styled/templated PDF beyond the platform export — DocuDesk remains
  the templating specialist.

## See also

- `openspec/specs/avg-verwerkingsregister/spec.md` — the capability
  this change deltas; its "Current Implementation Status" section
  documents the verified partial baseline.
- `openspec/specs/audit-trail-immutable/spec.md` — the write-side
  record this change cross-references instead of duplicating.
- `openspec/specs/notificatie-engine/spec.md` — the
  `x-openregister-notifications` precedent for the declarative dialect
  and its save-time 422 validation contract.
- `procest/openspec/changes/avg-verwerkingenlogging`,
  `docudesk/openspec/changes/processing-activity-export`,
  `scholiq/openspec/changes/avg-verwerkingsregister` — the superseded
  per-app changes (see table above).
- VNG Logging Verwerkingen (verwerkingenlogging API-standaard); AVG
  art. 5(2), 6, 9, 15, 30.
