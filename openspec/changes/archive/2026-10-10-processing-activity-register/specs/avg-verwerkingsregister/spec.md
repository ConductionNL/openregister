---
status: draft
---

# AVG Verwerkingsregister — Processing Activity Register & Verwerkingenlogging (delta)

## Purpose

Promote the AVG/GDPR processing register to a full platform capability,
absorbing the storage/logging/export/API layers of the procest
(`avg-verwerkingenlogging`), docudesk (`processing-activity-export`),
and scholiq (`avg-verwerkingsregister`) per-app changes: an
Art. 30(1)-complete, lifecycle-governed, audit-trail-versioned
verwerkingsactiviteitenregister; declarative app contribution via
`x-openregister-processing`; a per-access processing log covering
**reads** (the object audit trail already covers writes — that record
is cross-referenced, not duplicated) with flagged-fallback attribution,
batched asynchronous emission, and enforced retention; org-level
Art. 30 exports and a per-subject extract; a bearer-gated VNG Logging
Verwerkingen-shaped API; and per-register/per-app scoping with
admin-default, FG-delegated access.

## ADDED Requirements

### Requirement: Processing activities MUST be Art. 30(1)-complete, lifecycle-governed, and versioned via the audit trail (OR-PA-1)

The platform `Verwerkingsactiviteit` entity MUST carry every AVG
Art. 30(1) mandatory element: name, purpose (doelbinding), legal basis
(`rechtsgrond`) constrained to the six Art. 6(1) grounds (`consent` |
`contract` | `legal-obligation` | `vital-interests` | `public-task` |
`legitimate-interest`) with a statutory reference, a
`legitimateInterestAssessment` required when the basis is
`legitimate-interest`, a `specialCategories` flag with a mandatory
Art. 9 `specialCategoriesBasis` when set, categories of data subjects
and of personal data, (categories of) recipients, third-country
transfers with safeguards, retention period (ISO 8601 duration), and
security measures — plus a `confidential` flag (entries of the activity
are FG-only), `flagged` (fallback marker), owner and review-cycle
fields (`ownerUserId`, `reviewIntervalMonths`, `nextReviewAt`), and a
lifecycle `draft → active → retired`. Saves violating these constraints
MUST be rejected with HTTP 422 and a structured error naming the field.
Every mutation of an `active` activity MUST produce an immutable
audit-trail entry (per `audit-trail-immutable`) with prior versions
retrievable; `retired` activities MUST remain resolvable from existing
log and audit entries but MUST NOT accept new attribution.

#### Scenario: Legal basis outside the Art. 6 grounds is rejected
- **GIVEN** an activity being saved with `rechtsgrond: "because-we-want-to"`
- **WHEN** the save is processed
- **THEN** the response MUST be HTTP 422 with a structured error naming `rechtsgrond` and listing the six permitted grounds

#### Scenario: Special-category processing requires an Art. 9 basis
- **GIVEN** an activity being saved with `specialCategories: true` and an empty `specialCategoriesBasis`
- **WHEN** the save is processed
- **THEN** the save MUST be rejected with HTTP 422 naming the missing Art. 9 basis

#### Scenario: Legitimate interest requires an assessment
- **GIVEN** an activity being saved with `rechtsgrond: legitimate-interest` and no `legitimateInterestAssessment`
- **WHEN** the save is processed
- **THEN** the save MUST be rejected with HTTP 422

#### Scenario: Mutating an active activity leaves a retrievable version trail
- **GIVEN** an `active` activity with `bewaartermijn: P5Y`
- **WHEN** a privacy officer changes it to `P7Y`
- **THEN** an immutable audit-trail entry MUST record the before/after values, actor, and timestamp
- **AND** the previous version MUST remain retrievable for compliance evidence

#### Scenario: Retiring an activity preserves history and blocks new attribution
- **GIVEN** an `active` activity referenced by existing processing-log entries
- **WHEN** it is transitioned to `retired`
- **THEN** existing log and audit entries referencing it MUST remain intact and resolvable
- **AND** new processing MUST NOT be attributed to it (attribution resolution treats it as unresolvable, falling back per OR-PA-4)

#### Scenario: Review-due notification to the owner
- **GIVEN** an `active` activity with `ownerUserId: fg-user` and `nextReviewAt` within the configured review window
- **WHEN** the daily review sweep runs
- **THEN** `fg-user` MUST receive a Nextcloud notification (nl/en) linking to the activity

### Requirement: Apps MUST contribute processing activities and attribution declaratively via `x-openregister-processing` (OR-PA-2)

The system MUST treat the register- or schema-level annotation
`configuration['x-openregister-processing']` as the declarative source
of an app's processing contribution, with three blocks: `activities`
(a catalogue of the app's own processing activities, full Art. 30(1)
field set, keyed by `code`), `attribution` (a `default` activity
reference plus optional per-operation overrides for `read`, `create`,
`update`, `delete`, `export`), and `logReads` (boolean read-logging
opt-in, default `false`). On register import, catalogue entries MUST be
upserted by `code` within the organisation: created in lifecycle
`draft` when absent, and never overwriting fields of an existing
activity. Schema-level annotation inherits from register-level.
Attribution references MUST resolve via code or uuid. The legacy string
annotation `x-openregister-processing-activity` MUST remain valid as
shorthand for `attribution.default`; when both are present at the same
level the new dialect wins. Saving a schema or register with a
malformed annotation (unknown keys, catalogue entry violating OR-PA-1
constraints, non-boolean `logReads`, unresolvable-by-shape attribution)
MUST fail with HTTP 422 and a structured error, mirroring the
`x-openregister-notifications` validation contract. The imperative path
(`VerwerkingsactiviteitenController` CRUD,
`ObjectEntity::setProcessingActivityId()`) MUST remain available as
fallback for dynamic attribution.

#### Scenario: Register import seeds the app's catalogue as drafts
- **GIVEN** an app register whose `x-openregister-processing.activities` declares "Behandelen omgevingsvergunning" with purpose, `rechtsgrond: public-task`, betrokkenen, ontvangers, and `bewaartermijn`
- **WHEN** the register import runs on a fresh organisation
- **THEN** the activity MUST exist in the platform verwerkingsregister in lifecycle `draft`, organisation-scoped
- **AND** activation MUST require an explicit lifecycle transition by an authorised user

#### Scenario: Re-import never overwrites privacy-officer edits
- **GIVEN** a seeded activity whose `doelbinding` was amended and which was activated by the privacy officer
- **WHEN** the app's register import runs again with the original catalogue text
- **THEN** the existing activity MUST be left unmodified (matched by `code`, no field overwritten, no duplicate created)

#### Scenario: Schema attribution stamps write audit entries
- **GIVEN** a schema whose annotation sets `attribution.default` to a resolvable activity
- **WHEN** an object in that schema is updated
- **THEN** the audit-trail entry's `processingActivityId` MUST reference that activity

#### Scenario: Per-operation attribution override
- **GIVEN** a schema with `attribution.default: "act-a"` and `attribution.export: "act-b"`
- **WHEN** an object is read and then exported
- **THEN** the read's processing-log entry MUST reference `act-a`
- **AND** the export's entry MUST reference `act-b`

#### Scenario: Malformed annotation rejected at save time
- **GIVEN** a schema annotation whose catalogue entry has `rechtsgrond: "marketing"` or whose `logReads` is the string `"yes"`
- **WHEN** the schema is saved
- **THEN** the save MUST fail with HTTP 422 and a structured error naming the offending block and field

#### Scenario: Legacy string annotation keeps working
- **GIVEN** a schema carrying only the legacy `x-openregister-processing-activity: "<uuid>"`
- **WHEN** objects in that schema are mutated
- **THEN** attribution MUST resolve exactly as before this change
- **AND** the compliance check (`findUnannotatedSchemasWithPii`) MUST accept either annotation form as satisfying the attribution requirement

### Requirement: Reads of objects on opted-in schemas MUST produce processing-log entries (OR-PA-3)

The system MUST record a processing-log entry for every read or export
of an object whose schema (or register) opts in via `logReads: true`,
capturing: action (`read` | `export`), the attributed processing
activity, actor (NC user id, or the API-client identifier for
token-authenticated access), channel (`ui` | `api` | `graphql` | `mcp`
| `public` | `background`), register/schema/object references,
timestamp, and the processed data-subject identifiers (idType +
idValue, e.g. BSN) where the schema declares them. Purpose and legal
basis MUST be resolvable per entry through the referenced activity.
Mutations MUST NOT be duplicated into the processing log — the
hash-chained audit trail (which already carries
`processingActivityId`) remains the write-side record, and per-subject
reporting joins the two. A list/search response MUST produce a single
entry carrying `objectCount` (with per-object identifiers only when the
result set is 100 objects or fewer), never one entry per scanned row.
Schemas without the opt-in MUST produce no read entries.

#### Scenario: Reading a BSN-bearing object is logged
- **GIVEN** a schema with `logReads: true`, attribution to "Behandelen omgevingsvergunning", and a BSN subject-identifier field
- **WHEN** handler `jan` opens an object's detail via the UI
- **THEN** a processing-log entry MUST be recorded with `action: read`, `actor: jan`, `channel: ui`, the object reference, and subject identifier `{idType: BSN, idValue: <bsn>}`
- **AND** the entry MUST resolve to the activity's doelbinding and rechtsgrond

#### Scenario: No read logging without the opt-in
- **GIVEN** a schema without `logReads`
- **WHEN** objects in it are read and updated
- **THEN** no processing-log entry MUST be created for the read
- **AND** the update MUST still produce its attributed audit-trail entry

#### Scenario: Bulk list collapses to one entry
- **GIVEN** an opted-in schema and a list query returning 50 objects
- **WHEN** the query executes
- **THEN** exactly one processing-log entry MUST be recorded with `objectCount: 50` and the per-object subject identifiers (result set ≤ 100)

#### Scenario: API-client access is logged with the client identity
- **GIVEN** an external system reading an opted-in object with a bearer token
- **WHEN** the read executes
- **THEN** the entry MUST carry `channel: api` and `actor` set to the client identifier, not a Nextcloud user

#### Scenario: Export is a distinct logged action
- **WHEN** a user exports objects from an opted-in schema
- **THEN** a processing-log entry with `action: export` MUST be recorded under the schema's export attribution

### Requirement: Unattributed processing on logged schemas MUST fall back to a flagged fallback activity (OR-PA-4)

The platform MUST seed, per organisation, a fallback activity
(`code: niet-geclassificeerde-verwerking`, `flagged: true`). When a
processing-log entry must be written but attribution does not resolve —
no annotation, a dangling reference, or a `retired`/`draft` target —
the entry MUST be attributed to the fallback activity rather than being
dropped or written without an activity. The compliance surface
(`AvgComplianceService`) MUST expose the fallback entry count per
register/schema so the gap is visible and fixable. Nothing on an
opted-in schema is ever silently unlogged.

#### Scenario: Unmapped read hits the flagged fallback
- **GIVEN** a schema with `logReads: true` and no resolvable attribution
- **WHEN** a user reads an object in it
- **THEN** the processing-log entry MUST reference the seeded fallback activity
- **AND** the entry MUST NOT be dropped

#### Scenario: The compliance surface shows the unclassified gap
- **GIVEN** 17 fallback-attributed entries exist for register `procest`
- **WHEN** the FG opens the compliance check (or `runAllChecks()` is invoked)
- **THEN** the result MUST report the unclassified count per register/schema so the mapping gap can be fixed

### Requirement: Processing-log emission MUST be batched, asynchronous, and never block the primary action (OR-PA-5)

Processing-log entries MUST be buffered in-request and flushed after
the response as batched inserts; the primary read/export MUST complete
normally regardless of log-backend state. On flush failure the batch
MUST be spooled and retried by a background job; a persistent failure
MUST raise an administrator warning. Entries MUST NOT be silently
dropped, and log emission MUST NOT add a per-row synchronous write to
list rendering.

#### Scenario: Log backend outage does not block reads
- **GIVEN** the processing-log flush backend is temporarily unavailable
- **WHEN** a handler opens an opted-in object
- **THEN** the object MUST load normally
- **AND** the pending entries MUST be spooled and flushed when the backend recovers

#### Scenario: Persistent flush failure is surfaced, not swallowed
- **GIVEN** the spool retry has failed beyond the configured threshold
- **WHEN** the retry job gives up the attempt
- **THEN** an administrator warning MUST be raised naming the spooled batch
- **AND** the spooled entries MUST be retained for the next retry

#### Scenario: One request flushes as one batch
- **GIVEN** a request that triggers entries for a detail read and two relation reads on opted-in schemas
- **WHEN** the response has been sent
- **THEN** the entries MUST be persisted in a single batched flush, not one synchronous insert per entry during rendering

### Requirement: The processing log MUST be append-only with enforced retention and confidentiality (OR-PA-6)

Processing-log entries MUST be immutable through the application: no
update or delete endpoint exists for them on any surface (REST,
GraphQL, MCP). Entries MUST be retained for a configurable period
(default 3 years, per the VNG Logging Verwerkingen norm) and
hard-deleted by a background prune job after retention; the prune run
MUST itself be recorded. Entries attributed to a `confidential`
activity MUST be excluded from every non-FG query, export, and API
result, including the per-subject extract.

#### Scenario: Entries cannot be modified or deleted via the app
- **WHEN** any user, including an administrator, attempts to update or delete a processing-log entry through any API surface
- **THEN** the request MUST be rejected (no such route / RBAC denial)

#### Scenario: Retention prune removes expired entries and logs its run
- **GIVEN** retention configured at 3 years and entries older than that
- **WHEN** the prune job runs
- **THEN** the expired entries MUST be permanently removed
- **AND** a record of the purge (period, count) MUST be produced

#### Scenario: Confidential-activity entries are FG-only
- **GIVEN** entries attributed to an activity flagged `confidential` (e.g. fraud investigation)
- **WHEN** a non-FG administrator queries the log or generates a per-subject extract
- **THEN** those entries MUST be excluded from the results
- **AND** an FG-role user running the same query MUST see them

### Requirement: The register MUST support org-level Art. 30 export and a per-subject extract, scoped and PII-safe (OR-PA-7)

The system MUST export the verwerkingsregister (all `active` activities
with the full Art. 30(1) column set) as JSON, CSV (UTF-8 with BOM, one
row per activity), and PDF, every format carrying a header with the
controller identity (verwerkingsverantwoordelijke name/contact, FG
contact, from the register's identity fields), the scope, and the
generation timestamp; an optional register/app filter MUST narrow the
export to that slice. The system MUST additionally produce a
per-subject (betrokkene) extract — filtered by subject identifier
(idType + idValue) and period — joining processing-log entries AND the
subject's write-side audit-trail entries, each with timestamp, action,
actor, channel, and the attributed activity's name, purpose, and legal
basis, to support an Art. 15 inzageverzoek. Aggregate exports (Art. 30,
counts) MUST be structurally incapable of carrying literal personal
data: their data model contains only counts, category/base/activity
identifiers and display names, and configured identity strings — never
entity values, document text, or file names. Every export generation
MUST itself produce a processing-log entry. An unknown `format` yields
HTTP 400; a period exceeding `processing_export_max_range_days`
(default 366) yields HTTP 422.

#### Scenario: Art. 30 export with controller-identity header
- **GIVEN** active activities and configured controller identity "Gemeente Voorbeeld" / FG "fg@voorbeeld.nl"
- **WHEN** the privacy officer exports the register as PDF
- **THEN** the document MUST list every active activity with the Art. 30(1) columns
- **AND** the header MUST show the controller name, FG contact, scope, and generation timestamp

#### Scenario: Per-app slice of the Art. 30 export
- **GIVEN** activities contributed by procest and scholiq in one organisation
- **WHEN** the export is requested filtered to the procest register
- **THEN** only activities attributed to that register's slice MUST appear
- **AND** the header MUST name the scope

#### Scenario: Per-subject extract joins reads and writes
- **GIVEN** processing-log read entries and audit-trail update entries exist for objects carrying BSN `123456789`
- **WHEN** an FG-role user generates the extract for that BSN over January–June 2026
- **THEN** the extract MUST contain both the reads and the writes in the period, each with timestamp, action, actor, channel, and the activity's name, purpose, and legal basis
- **AND** the extract generation MUST produce a processing-log entry with `action: export`

#### Scenario: Aggregate export carries no literal PII
- **GIVEN** in-scope processing of an object containing the value "Pieter Jansen" from a file named `bezwaar-jansen.pdf`
- **WHEN** the Art. 30 export is generated in each format
- **THEN** no output byte stream may contain "Pieter Jansen" or the file name

#### Scenario: Range and format validation
- **WHEN** an export is requested with `format=docx`, or a per-subject extract spans 500 days against a 366-day maximum
- **THEN** the responses MUST be HTTP 400 (unknown format) and HTTP 422 (range, naming the configured maximum) respectively

### Requirement: Register, log, and export surfaces MUST be admin-only by default with FG delegation and tenant isolation (OR-PA-8)

The system MUST require the Nextcloud `admin` group by default on all
verwerkingsregister management, processing-log inquiry, and export
surfaces (no
`#[NoAdminRequired]`; NC SecurityMiddleware default posture), with
delegation of the inquiry and export surfaces to a configurable
privacy-officer (FG) group. Activities, log entries, exports, and API
results MUST be organisation-scoped: one organisation's privacy officer
MUST NOT see another organisation's register or log. Every list surface
MUST accept a register filter so an app's FG view can present only its
own slice.

#### Scenario: Non-privileged user is denied
- **GIVEN** an authenticated user in neither `admin` nor the privacy-officer group
- **WHEN** they call any register, log-inquiry, or export endpoint
- **THEN** the request MUST be rejected with no data disclosed

#### Scenario: Privacy-officer delegation
- **GIVEN** a user in the configured privacy-officer group who is not an admin
- **WHEN** they open the log inquiry and generate a per-subject extract
- **THEN** both MUST succeed

#### Scenario: App slice via register filter
- **GIVEN** log entries from the procest and scholiq registers in one organisation
- **WHEN** the inquiry is called with the procest register filter
- **THEN** only entries from that register MUST be returned

#### Scenario: Organisations are isolated
- **GIVEN** two organisations sharing an instance
- **WHEN** organisation A's privacy officer lists activities or log entries
- **THEN** only organisation A's records MUST be returned, with no indication of organisation B's data

### Requirement: A bearer-gated VNG Logging Verwerkingen-shaped API MUST be exposed (OR-PA-9)

The platform MUST expose REST endpoints shaped after the VNG Logging
Verwerkingen API standard for listing and filtering verwerkingsacties
(by betrokkene idType + idValue, period, activity, actor) and
processing activities, paginated, authenticated by bearer tokens
carrying a `verwerkingenlogging` scope and optionally restricted to a
register set. Confidential-activity entries MUST be excluded unless the
token carries the FG scope. Unauthenticated or out-of-scope requests
MUST receive 401/403 without disclosing log content.

#### Scenario: External audit tool lists verwerkingsacties
- **GIVEN** an external tool holding a bearer token with the `verwerkingenlogging` scope
- **WHEN** it requests verwerkingsacties filtered by period and betrokkene identifier
- **THEN** it MUST receive the matching entries in the standard's resource shape, paginated
- **AND** confidential-activity entries MUST be excluded (no FG scope on the token)

#### Scenario: Register-scoped token sees only its slice
- **GIVEN** a token restricted to the procest register
- **WHEN** it lists verwerkingsacties without filters
- **THEN** only entries from the procest register MUST be returned

#### Scenario: Unauthenticated access is rejected
- **WHEN** a verwerkingen endpoint is called without a valid bearer token
- **THEN** the response MUST be 401 and no log content may be disclosed
