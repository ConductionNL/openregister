# Design: AVG Verwerkingsregister

## Architectural premise

OpenRegister already covers most of GDPR Art 30 incidentally. The
audit-trail table carries every write with hash-chained tamper
evidence + per-write columns for `processing_activity_id`,
`processing_activity_url`, `processing_id`, `confidentiality`, and
`retention_period`. The PII detection layer (`GdprEntity`) tracks per-
object personal-data inventory. Multi-tenant isolation is enforced via
`MultiTenancyTrait`. Per-property RBAC + condition-matching lets us
gate access by purpose.

What's missing is **the verwerkingsregister entity itself** — the
table that *describes* each processing activity (legal basis, purpose,
data categories, retention rule, recipients, third-country transfers,
technical/organisational measures) — and the **trigger contract** that
pins every object write to a specific processing activity so the
audit trail's `processing_activity_id` gets populated automatically.

Once those land, GDPR Art 15 (inzage), Art 17 (vergetelheid), and
Art 20 (portability) endpoints fall out of composing primitives we
already have.

## Data model

### `oc_openregister_verwerkingsactiviteiten`

A dedicated table — **not** a register that eats its own dogfood.
Reasons:

1. The verwerkingsregister has a fixed AVG-mandated schema — no need
   for OpenRegister's flexibility, and a stable schema makes it easier
   to ship compliance reports / PDF exports (verantwoordingsdocument).
2. It would be circular to bootstrap (the verwerkingsregister
   processing-activity would itself be a "processing activity").
3. The audit-trail FK already points at it via `processing_activity_id`,
   which is cleaner pointing at a stable table than at a
   register-managed object.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | auto-increment |
| `uuid` | varchar(36) UNIQUE | natural key, written as `processing_activity_id` on audit rows |
| `code` | varchar(64) NULL | optional short readable key (e.g. `v-2026-001`) for printed registers |
| `naam` | varchar(255) NOT NULL | name |
| `beschrijving` | text NULL | free-form description |
| `doelbinding` | text NOT NULL | purpose limitation per Art 5(1)(b) |
| `rechtsgrond` | varchar(64) NOT NULL | Art 6 legal basis vocabulary (see below) |
| `categorieen_betrokkenen` | json NULL | array of strings (e.g. `["burgers", "medewerkers"]`) |
| `categorieen_persoonsgegevens` | json NULL | array of strings or `{name, special?: bool}` |
| `bewaartermijn` | varchar(64) NULL | ISO-8601 duration string (e.g. `P10Y`) |
| `ontvangers` | json NULL | recipients array of `{naam, type}` |
| `doorgifte_buiten_eu` | json NULL | `{ja: bool, landen: [...], waarborgen: "..."}` |
| `technische_maatregelen` | text NULL | technical security measures |
| `organisatorische_maatregelen` | text NULL | organisational security measures |
| `verwerkingsverantwoordelijke` | json NULL | controller `{naam, adres, contact}` |
| `contactgegevens_fg` | json NULL | DPO contact `{naam, email, telefoon}` |
| `organisation_id` | varchar(64) NULL | multi-tenancy isolation |
| `status` | varchar(32) NOT NULL DEFAULT `'concept'` | `concept` \| `published` \| `archived` |
| `created` | timestamp NOT NULL | |
| `updated` | timestamp NOT NULL | |

Indexes: `idx_vrw_uuid` UNIQUE on `uuid`, `idx_vrw_code` on `code`,
`idx_vrw_organisation` on `organisation_id`, `idx_vrw_status` on
`status`.

### `rechtsgrond` vocabulary (Art 6 GDPR)

```
toestemming               (consent)
overeenkomst              (contract)
wettelijke_verplichting   (legal obligation)
vitaal_belang             (vital interest)
publieke_taak             (public task)
gerechtvaardigd_belang    (legitimate interest)
```

Validated at `VerwerkingsactiviteitMapper::insert/update` time.

### What we do NOT add

- A separate `verwerkingen` audit table — `oc_openregister_audit_trails`
  *is* the per-write log; we only need the activity *catalog* table.
- Per-data-subject consent ledger — out of scope; tracked via the
  existing `GdprEntity` PII detection + this register's
  `categorieen_betrokkenen`. Granular consent ledger is a follow-up.

## Trigger contract: how does an audit row get tagged?

Two-tier resolution at write time, evaluated by
`AuditTrailMapper::createAuditTrail`:

1. **Per-action override** (highest precedence) — set directly on the
   `ObjectEntity` before save (e.g. by a custom controller for a
   data-subject access request endpoint). Surfaces via a transient
   `ObjectEntity::$processingActivityId` field that is read but not
   persisted on the object itself.
2. **Per-schema default** — Schema configuration carries
   `x-openregister-processing-activity: <uuid|code>`. Set on the
   schema once, every write through that schema is tagged.
3. **Per-register default** (fallback) — same key on Register
   configuration. Used when the schema doesn't override.
4. **Unset** — if none of the above resolve, the audit row's
   `processing_activity_id` stays null. This is the existing behaviour;
   nothing breaks for callers that haven't opted in.

The lookup is a string compare against `code` first, then `uuid`. A
warning is logged if the schema/register annotation references an
unknown activity (typo guard) but the write proceeds.

## API surface

### v1 (this phase)

```
GET    /api/avg/verwerkingsactiviteiten
POST   /api/avg/verwerkingsactiviteiten
GET    /api/avg/verwerkingsactiviteiten/{id}
PUT    /api/avg/verwerkingsactiviteiten/{id}
DELETE /api/avg/verwerkingsactiviteiten/{id}
```

CRUD controller (`VerwerkingsactiviteitenController`) following the
same pattern as the existing `Registers` / `Schemas` controllers.

Authorization: admin-only for write operations; read open to any
authenticated user (the verwerkingsregister is required to be public
under Art 30(4) for supervisory authorities and indirectly for data
subjects via inzage requests).

### v2 (follow-up — data-subject rights)

```
GET  /api/avg/inzage?subject={uuid|email|bsn}        (Art 15)
POST /api/avg/vergetelheid?subject={...}             (Art 17)
GET  /api/avg/portabiliteit?subject={...}&format=json (Art 20)
GET  /api/avg/verantwoording                         (full register report for AP)
```

These compose `GdprEntity` lookup + audit-trail filtering by
`processing_activity_id` + bulk soft-delete. Deferred to Phase 2 to
keep this change reviewable.

## Wire-in

1. **Migration** — `Version1Date20260430160000` adds the table.
2. **Entity + Mapper** — `Db/Verwerkingsactiviteit.php` + `Db/VerwerkingsactiviteitMapper.php` with the standard Nextcloud `Entity` + `QBMapper` pattern.
3. **AuditTrailMapper hook** — `createAuditTrail` reads the schema/register configuration via the cached schema/register lookups (no extra DB round-trip in the hot write path; the schema is already loaded).
4. **Controller + routes** — `VerwerkingsactiviteitenController` + `/api/avg/verwerkingsactiviteiten` routes wired in `appinfo/routes.php`.
5. **Service registration** — `Application::register()` resolves the new mapper + controller via constructor DI.

## Test coverage

- **Mapper round-trip** — insert / find / update / delete round-trip
  through `VerwerkingsactiviteitMapper`.
- **Validation** — invalid `rechtsgrond` rejected.
- **Audit hook** — create a verwerkingsactiviteit, annotate a schema
  with `x-openregister-processing-activity`, save an object on that
  schema, assert the resulting audit row carries the right
  `processing_activity_id`.
- **Per-register fallback** — same as above but with the annotation on
  the register, schema unset; assert the audit row inherits the
  register-level default.

## Risk + mitigations

| Risk | Mitigation |
|---|---|
| Audit-trail FK pointing at a `uuid` that gets deleted leaves orphan rows | Soft-delete only (`status='archived'`); hard-delete blocked when the activity has audit rows. |
| Operator typo in `x-openregister-processing-activity` annotation silently nulls audit rows | Warning logged at write time when the lookup misses (caught by log monitoring). |
| Multi-tenant cross-leak — activity A in tenant X used by schema in tenant Y | `organisation_id` filter on every mapper query. |
| Hot audit-write path takes an extra mapper round-trip | The schema entity is already loaded in the save pipeline; the annotation read is `$schema->getConfiguration()['x-openregister-processing-activity']`, no DB call. The activity lookup itself is cached per-request via the existing schema/register caches. |

## Open questions resolved

- **Q:** Dedicated table vs OpenRegister-as-register? **A:** Dedicated
  table (rationale above).
- **Q:** Trigger linkage — per-schema, per-register, or per-action?
  **A:** All three with documented precedence (action > schema >
  register).
- **Q:** Audit-trail dependency direction? **A:**
  `audit_trails.processing_activity_id` is a soft FK to
  `verwerkingsactiviteiten.uuid`. Hash-chained tamper evidence already
  locks the audit-trail content; a hard FK constraint isn't needed.
