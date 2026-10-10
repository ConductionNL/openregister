# Design: Processing Activity Register — Platform Verwerkingsregister & Verwerkingenlogging

## Verified baseline (2026-06-11)

- `lib/Db/Verwerkingsactiviteit.php` — platform entity (QBMapper table,
  NOT an OR object): uuid, `code`, naam, beschrijving, doelbinding,
  rechtsgrond, categorieenBetrokkenen, categorieenPersoonsgegevens,
  bewaartermijn, ontvangers, doorgifteBuitenEu,
  technische/organisatorischeMaatregelen, verwerkingsverantwoordelijke,
  contactgegevensFg, organisationId, status (`concept` default).
  Missing for Art. 30(1)-completeness as the three apps specced it:
  special-category flag + Art. 9 basis, legitimate-interest assessment,
  confidentiality flag, lifecycle transitions, owner/review fields,
  save-time Art. 6 enum validation, versioning of mutations.
- `lib/Db/VerwerkingsactiviteitMapper.php` — `findByCode()`,
  `resolveReference()` (code → uuid). `VerwerkingsactiviteitenController`
  — CRUD + `verantwoording()`.
- `lib/Db/AuditTrailMapper.php` — `resolveProcessingActivityId()` reads
  `configuration['x-openregister-processing-activity']` from the schema,
  inheriting from the register, and stamps `processingActivityId` onto
  **write** audit-trail entries. `ObjectEntity::setProcessingActivityId()`
  is the imperative override (used by `DsarService`).
- `lib/Service/AvgComplianceService.php` —
  `findUnannotatedSchemasWithPii()` + `runAllChecks()`; public constant
  `ANNOTATION_KEY`.
- `audit-trail-immutable` — hash-chained, immutable write record. **No
  read coverage**; `SearchTrail` logs query shapes but no
  activity/purpose context.
- `notificatie-engine` spec — the `x-openregister-notifications`
  precedent: schema-annotation as single source of truth (ADR-031), no
  shadow rule tables, save-time 422 + structured-error validation
  (throttle-window-grammar contract).

## Key decisions

### D1. Activities stay a platform entity table, not a per-app OR schema

scholiq modeled `ProcessingActivity` as an OR schema in its own
register; procest as schemas in the procest register. Centralising
means **one** verwerkingsregister per organisation, owned by the
platform: the shipped `Verwerkingsactiviteit` entity + mapper is that
home and is already wired into the audit trail. Per-app OR schemas
would shard the register (an FG would have to union N registers to
answer the AP) and would let apps mutate each other's compliance data.
Consequence for the thinned app changes: app UI surfaces consume the
`verwerkingsactiviteiten` API (or the platform UI), not the objects
API. The main spec's older prose suggesting "modeled as an OR register
and schema" is superseded by the shipped entity implementation this
change extends.

### D2. `x-openregister-processing` — one annotation, three blocks

Register- or schema-level annotation, mirroring the
`x-openregister-notifications` dialect mechanics (annotation is the
single source of truth; validated on save with 422 + structured
errors):

```jsonc
"x-openregister-processing": {
  // (a) catalogue — activities this app contributes, seeded as draft
  "activities": [
    {
      "code": "procest-behandelen-omgevingsvergunning",
      "naam": "Behandelen omgevingsvergunning",
      "doelbinding": "…",
      "rechtsgrond": "public-task",
      "rechtsgrondReferentie": "Omgevingswet art. 5.1",
      "categorieenBetrokkenen": ["aanvrager"],
      "categorieenPersoonsgegevens": ["NAW", "BSN"],
      "ontvangers": ["behandelend ambtenaar"],
      "bewaartermijn": "P10Y"
    }
  ],
  // (b) attribution — which activity justifies operations on this
  // schema; flat default + per-operation overrides
  "attribution": {
    "default": "procest-behandelen-omgevingsvergunning",
    "export": "procest-dossier-verstrekking"
  },
  // (c) read logging opt-in (default false — volume control)
  "logReads": true
}
```

- Catalogue entries are **upserted by `code`** on register import:
  create as `draft` when absent; never overwrite fields of an existing
  activity (the privacy officer owns the content after seeding — the
  school/municipality is the controller, scholiq D3). Seeds are
  organisation-scoped.
- `attribution` references resolve through
  `VerwerkingsactiviteitMapper::resolveReference()` (code or uuid);
  schema-level inherits from register-level, exactly like the legacy
  key. The legacy string `x-openregister-processing-activity` remains
  valid and equivalent to `{"attribution": {"default": "<ref>"}}` —
  `AvgComplianceService::ANNOTATION_KEY` checks accept either form.
- Why not auto-activate seeds: misdescribing processing is worse than
  draft entries; activation is the controller's explicit decision
  (scholiq's D3, adopted platform-wide).
- Imperative fallback stays: `VerwerkingsactiviteitenController` CRUD
  for operators, `ObjectEntity::setProcessingActivityId()` for code
  paths whose attribution is dynamic (DSAR precedent).

### D3. Reads get a dedicated processing-log table, not audit-trail rows

Writes already produce hash-chained audit entries stamped with
`processingActivityId` — duplicating them into a second log would
double storage and create a reconciliation problem. Reads go to a new
append-only table `oc_openregister_processing_log`:

| column | notes |
|---|---|
| `id` | autoincrement |
| `uuid` | entry uuid (VNG resource identity) |
| `activity_id` | uuid of the Verwerkingsactiviteit, indexed |
| `action` | `read`, `export` (mutations live in the audit trail) |
| `actor` | NC user id or API-client identifier, indexed |
| `channel` | `ui`, `api`, `graphql`, `mcp`, `public`, `background` |
| `register_id` / `schema_id` | scoping slice, indexed |
| `object_uuid` | indexed |
| `subject_id_type` / `subject_id_value` | e.g. `BSN`/`123456789` — composite-indexed for per-betrokkene queries |
| `object_count` | bulk/list entries |
| `confidential` | denormalised from the activity at write time |
| `created` | indexed (period queries + retention prune) |

Aggregate-friendly by construction: plain indexed columns (no JSON
parsing for the hot queries), per-betrokkene composite index, period
index, and `(register_id, activity_id, created)` for Art. 30 / app
slices. Purpose and legal basis are **not** denormalised per row — they
join from the activity (and its audit-trail history for point-in-time
correctness); rows stay narrow. Why not hash-chain it: the chain
serialises writes (a per-insert chain head lock kills batched
high-volume ingestion); immutability here is enforced by surface (no
update/delete endpoints, R-append-only) like procest specced, while the
legally-heavier mutation record keeps its chain.

### D4. Emission pipeline: in-request buffer → deferred batch flush → spool

`ProcessingLogService::log()` appends to an in-memory buffer; the
buffer is flushed **after the response** (NC's post-response hooks /
short-lived QueuedJob) as one batched insert. On flush failure the
batch is spooled (app-data file, retried by background job) and a
persistent failure raises an admin warning — entries are never silently
dropped, and the primary action never waits on or fails with the log
(procest's "emit, never block" contract). List/search responses produce
**one** entry with `object_count` (+ per-object subject identifiers
only when the result set is ≤100, mirroring the existing main-spec bulk
scenario) — never one row per scanned row.

### D5. Read logging is opt-in per schema

`logReads` defaults to `false`. Rationale: read volume on
non-person-bearing schemas (catalogi, configuration, reference data) is
pure cost with zero AVG meaning; the legal duty attaches to
personal-data processing. `AvgComplianceService` closes the honesty
loop: schemas with detected PII but neither attribution nor `logReads`
already surface in `findUnannotatedSchemasWithPii()`; the check gains
the dialect-aware variant. Opt-in + flagged fallback together give
procest's guarantee: once a schema is in scope, nothing on it is
silently unlogged.

### D6. Flagged fallback activity, seeded per organisation

A platform-seeded activity `code: "niet-geclassificeerde-verwerking"`
(naam "Niet-geclassificeerde verwerking", `flagged: true`) absorbs
processing on read-logged schemas whose attribution does not resolve
(missing mapping, dangling reference, removed activity). The FG
dashboard / compliance check surfaces its entry count so the gap is
visible and fixable. This is procest's pattern promoted to the
platform, and the dynamic equivalent of docudesk's
`no-grondslag-recorded` bucket.

### D7. Exports computed on demand; per-subject extract joins log + activities

Art. 30 export = the activity register itself (active entries, full
Art. 30(1) column set, controller-identity header from
`verwerkingsverantwoordelijke`/`contactgegevensFg`), JSON/CSV (UTF-8
BOM)/PDF — nothing persisted (docudesk D1: a derived record cannot
drift; the downloaded file is the snapshot). The per-subject extract
queries `processing_log` by `(subject_id_type, subject_id_value,
period)` joined to activities (name, doelbinding, rechtsgrond per
entry) **plus** the write-side audit-trail entries for the same
subject's objects — one extract covering reads and mutations for an
Art. 15 answer. Aggregate exports carry the structural no-literal-PII
contract (docudesk D2): counts, category/base/activity identifiers,
identity strings — no field can carry an entity value, document text,
or file name. The per-subject extract necessarily contains the
subject's own identifiers; it is FG-gated and its generation is itself
logged. Range bound: `processing_export_max_range_days` (default 366) →
422.

### D8. VNG Logging Verwerkingen API: bearer-gated, platform routes

`/apps/openregister/api/verwerkingen/...` exposing verwerkingsacties
(list/filter by betrokkene idType+idValue, period, activity, actor) and
verwerkingsactiviteiten in the standard's resource shape, paginated.
Auth: bearer tokens (procest's `zgw-autorisaties-api` posture) with a
`verwerkingenlogging` scope; `confidential` entries require the FG
scope. The platform serves all registers; tokens can be scoped to a
register set so a municipality can hand its audit tool a procest-only
token.

### D9. Access model

Admin-only by default (NC SecurityMiddleware default posture — no
`#[NoAdminRequired]` on the register/log/export/API-management
surfaces). Delegation to a `privacy-officer` NC group (FG) for the
inquiry/export surfaces; `confidential`-activity entries visible to FG
only. Everything organisation-scoped via the existing multi-tenancy
(activities carry `organisationId`; log rows inherit the object's
organisation). Per-register/per-app filtering is a first-class query
parameter on every surface, so each app's FG view requests its own
slice.

## Supersession map (per-app requirement → OR requirement)

OR requirement keys refer to `specs/avg-verwerkingsregister/spec.md` in
this change.

| # | Per-app requirement (superseded) | Absorbed by OR requirement |
|---|---|---|
| P1 | procest: Processing activities MUST be maintained in a register | **OR-PA-1** (platform register, Art. 30(1) fields, lifecycle, validation) |
| P2 | procest: Every processing of personal data MUST produce a log entry (incl. reads) | **OR-PA-3** (reads/exports) + existing `audit-trail-immutable` attribution (writes) + **OR-PA-5** (never-blocking emission) |
| P3 | procest: Processing MUST be attributable to an activity, with a visible fallback | **OR-PA-2** (declarative attribution) + **OR-PA-4** (flagged fallback) |
| P4 | procest: The processing log MUST be append-only with enforced retention | **OR-PA-6** (append-only, retention, confidential FG-only) |
| P5 | procest: The FG MUST be able to query and export the log per betrokkene | **OR-PA-7** (per-subject extract) + **OR-PA-8** (FG delegation, scoping) |
| P6 | procest: A VNG Logging Verwerkingen-shaped API MUST be exposed | **OR-PA-9** |
| D1 | docudesk: Aggregate MUST cover the four activity categories over a bounded period | **OR-PA-7** (org-level scoped export, bounded range); category semantics + domain sources stay in docudesk's catalogue/UI |
| D2 | docudesk: Legal-bases breakdown MUST include a no-grondslag-recorded bucket | **OR-PA-4** (flagged fallback is the platform's visible-gap bucket); docudesk keeps the bucket in its domain views |
| D3 | docudesk: Retention references read from schema archival annotations at request time | **OR-PA-7** (export resolves retention from activity `bewaartermijn`, "not declared" when absent) |
| D4 | docudesk: Exports JSON/CSV/PDF with controller-identity header | **OR-PA-7** + **OR-PA-1** (identity lives on the platform activity register: verwerkingsverantwoordelijke / contactgegevensFg) |
| D5 | docudesk: No output MUST contain literal personal data | **OR-PA-7** (structural no-literal-PII contract on aggregate exports) |
| D6 | docudesk: Access MUST be admin-only in v1 | **OR-PA-8** |
| D7 | docudesk: Admin UI MUST provide settings and an export surface | identity settings absorbed by **OR-PA-1/OR-PA-7**; the docudesk UI surface itself stays app-side (thinned change) |
| S1 | scholiq: ProcessingActivity MUST carry the AVG Art. 30(1) mandatory elements | **OR-PA-1** |
| S2 | scholiq: Seed catalogue of own processing as drafts | **OR-PA-2** (catalogue block, seed-as-draft, upsert-by-code); catalogue *content* stays scholiq's |
| S3 | scholiq: Mutations of active entries MUST be audit-trail-backed | **OR-PA-1** (versioning scenario) |
| S4 | scholiq: Register exportable per Art. 30(4) + audit-pack inclusion | **OR-PA-7** (export); audit-pack ZIP inclusion stays scholiq-side |
| S5 | scholiq: Review reminders in the verified notification dialect | **OR-PA-1** (owner/review fields + review-due notification — platform entity, so the platform notifies; scholiq drops its rule) |
| S6 | scholiq: UI declarative, access OR-delegated | **OR-PA-8** (privacy-officer delegation); manifest/UI pages stay scholiq-side against the platform API |

## Performance

Read-logging is the high-volume path; the design budgets for it
explicitly:

- **Opt-in per schema** (D5) — the instance only pays for
  person-bearing schemas.
- **Batched async writes** (D4) — one multi-row insert per request
  after the response; list results collapse to one row; spool absorbs
  backend hiccups without user-visible latency.
- **Aggregate-friendly storage** (D3) — narrow rows, no JSON in hot
  filters, composite indexes for the three query families
  (per-betrokkene, per-activity/register/period, retention prune);
  counting queries never hydrate rows.
- **Retention prune** (default `P3Y`, configurable) is a batched
  background delete by the `created` index; the prune run is itself
  logged. If a deployment outgrows the table, the storage backend can
  swap behind `ProcessingLogService` without spec change (procest's
  note, adopted).

## Risks / Trade-offs

- **Volume underestimation** — a busy zaaksysteem can generate
  millions of read rows/year. Mitigated by D3–D5 + retention; the spec
  deliberately allows one-entry-per-list-response rather than per-row.
- **Log is not hash-chained** — deliberate (D3); mutation evidence
  keeps the chain, the read log's integrity posture is
  append-only-by-surface + DB privileges. Recorded as an explicit
  trade-off for the FG.
- **Apps mis-declaring catalogues** — seeds are drafts; activation is a
  human decision; the compliance check shows unattributed/unlogged PII
  schemas. Wrong seeds surface, they don't silently become the legal
  record.
- **Legacy annotation coexistence** — two annotation keys resolve
  attribution; precedence is specified (new dialect wins when both
  present on the same level) and the compliance check accepts either,
  so no flag-day migration for existing deployments.
- **Per-app changes already in flight** — procest/docudesk/scholiq must
  thin their changes before applying them, or they will build app-local
  copies of layers landing here. The supersession table is the
  coordination artifact; task 7.3 notifies all three.
