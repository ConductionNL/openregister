## Context

An object slug is entity metadata (`ObjectEntity::$slug`, magic-table column `_slug`,
`varchar(255)`, non-unique index `<table>_slug_idx`). It is written in three places
today, and they disagree:

| Where | What it does today |
|---|---|
| `SaveObject::setDefaultValues()` (`lib/Service/Object/SaveObject.php` ~1635) | calls the private `SaveObject::generateSlug()` (~2005), which slugifies `objectSlugField` and appends `-<time()>`, and puts the result in the object **data** as `slug`. The magic mapper has no such property, so it lands in `@self.relations.slug` and `MagicMapper` logs "Discarding 1 property … slug" (~5036) on every save. |
| `SaveObject::setSelfMetadata()` (~4609) | takes a client-supplied `@self.slug` or top-level `slug` and calls `setSlug()`. `ObjectsController` lets this through on purpose because integrations use it. |
| `MetadataHydrationHandler::hydrateObjectMetadata()` (`lib/Service/Object/SaveObject/MetadataHydrationHandler.php` ~179) | when `objectSlugField` is set, slugifies the field with `createSlug()` and calls `setSlug()` **on every save**. It runs last, so it wins. That is why a rename re-slugs and the `-<time()>` suffix never takes effect. |

`MetadataHydrationHandler::generateSlug()` (~813: `_slugFrom` → `_titleField` →
`name`/`title`/`label`/`slug` → schema title) has no production caller. Only
`tests/Service/BulkAndHandlersIntegrationTest.php` (~491–524) calls it.

The bulk path (`lib/Service/Object/SaveObjects.php`) builds a temporary entity per row in
`prepareMixedSchemaObject()` (~1477) and `prepareSingleSchemaObject()` (~1693), calls
`SaveObject::hydrateObjectMetadata()`, and copies `getSlug()` back into `@self` through
`applyHydratedMetadata()` (~1766). `MagicBulkHandler::withDisplayColumns()`
(`lib/Db/MagicMapper/MagicBulkHandler.php` ~265) then writes
`$selfData['slug'] ?? $object['slug']` into `_slug`. The upsert
(`INSERT … ON CONFLICT (_uuid) DO UPDATE` / `ON DUPLICATE KEY UPDATE`, ~770–812)
overwrites every column except `_uuid`, `_created` and `_version`.

The read side already refuses some ambiguity. `MagicMapper::findInRegisterSchemaTable()`
(`lib/Db/MagicMapper.php` ~6467) matches `_id OR _uuid OR _slug OR _uri` and throws
`MultipleObjectsReturnedException` when a second row comes back. That exception logs
nothing, and `ObjectsController::show()` (~2936) catches only `DoesNotExistException` and
`NotAuthorizedException`, so it escapes as an unhandled error. The cross-table locate
(`locateIdentifierInMagicTables()`, ~7176) unions all magic tables with `LIMIT 1`, so on
that path the first row wins.

Storage: since the blob table was retired, every object row lives in a magic table.
`SaveObject`'s `$objectEntityMapper` and `$unifiedObjectMapper` are both `MagicMapper`,
and `GetObject` reads through `MagicMapper`. Objects of a schema with an
`objectSource` provider are written by that provider
(`SaveObject::delegateObjectSourceWrite()`) and never reach hydration. This change does
not manage their slugs.

`Schema::validateConfigurationArray()` (`lib/Db/Schema.php` ~2872) drops every key that
is not on `$stringFields`, `$boolFields`, `$passThrough` or a named branch.
`objectSlugField` is on none of them, so it is lost on save and on import (both go
through `Schema::setConfiguration()`; import via `Schema::hydrate()` ~2058).

The schema editor in this app (`src/modals/schema/EditSchema.vue`) renders
`CnSchemaFormDialog` from `@conduction/nextcloud-vue` (2.72.0). Its
`CnSchemaConfigurationTab.vue` already shows NcSelect dropdowns for `objectNameField`,
`objectDescriptionField`, `objectImageField` and `objectSummaryField` over
`propertyOptions`. It has no slug field and no suffix switch. OpenRegister cannot add a
control to that tab from its own `src/`.

Decisions taken outside this document and treated as fixed: Robert Zondervan
(2026-10-19): uniqueness per register + schema, OpenCatalogi #1592 guards catalogue
scope; a slug is frozen at rename; the slug source is configurable per schema. Remco
Damhuis (2026-10-22): a backfill for existing objects. Working assumptions, pending
Robert's answer (issue plan Q5–Q8): an ambiguous lookup is an error, the backfill has a
default mode and `--reslug`, client slugs follow the same rules, and a re-import with a
new uuid waits for the slug.

## Goals / Non-Goals

**Goals:**

- `objectSlugField` and `objectSlugSuffix` round-trip through schema save, import and the
  schema editor.
- One place decides an object's slug, for single save, bulk save and the backfill alike.
- A slug, once written, is stable. A collision never fails a save and never duplicates a
  slug the pipeline itself writes.
- A duplicate that already exists is visible: a 409 on lookup, a log line naming both
  uuids, and a line in the backfill report. It is never silently resolved.
- Zero behaviour change for schemas without `objectSlugField`, and no data written on
  upgrade.

**Non-Goals:**

- Uniqueness across an OpenCatalogi catalogue (ConductionNL/opencatalogi#1592).
- A database-level guarantee (unique index). See D9.
- Register-less or register-only slug lookups across tables
  (`findAcrossAllMagicTables`). A slug is unique per register + schema by design, so a
  lookup without both cannot be unique, and it keeps its current behaviour.
- The HTML 404 page for an unknown slug that was seen in the reproduction.
- Re-slugging on restore of a soft-deleted object beyond the collision check: a restored object keeps its slug unless another live object holds it by then (see Risks).
- Slugs of objects stored by an external `objectSource` provider.

## Decisions

### D1 — One `ObjectSlugHandler` owns every slug write

A new `lib/Service/Object/SaveObject/ObjectSlugHandler.php` holds the whole rule:
candidate, freeze, uniqueness, suffix and logging. It is injected into `SaveObject`,
`SaveObjects` and the backfill service. `MetadataHydrationHandler::hydrateObjectMetadata()`
stops touching the slug, and `setSelfMetadata()` stops calling `setSlug()`.

Interface (indicative):

```php
// single save: decides and sets the entity's slug
public function applyOnSave(
    ObjectEntity $entity, Register $register, Schema $schema,
    ?string $originalSlug, ?string $clientSlug, string $path = 'single'
): void;

// bulk save: decides slugs for one register+schema group, in batch order
public function applyToBatch(array &$rows, Register $register, Schema $schema): void;

// candidate only: client slug, else slugified objectSlugField value, else null
public function candidate(array $objectData, Schema $schema, ?string $clientSlug): ?string;
```

*Alternative rejected:* keeping the logic in `hydrateObjectMetadata()`. That method also
runs for name pre-caching (`SaveObject::preCacheParentName()`, ~6191) and on a temporary
entity in bulk, neither of which knows the stored slug. A hydrator that queries the
database for collisions would also be called where no write follows.

### D2 — Freeze is decided against the slug stored before this save

`prepareObjectForUpdate()` (~4453) captures `$originalSlug = $existingObject->getSlug()`
**before** `setSelfMetadata()` runs, and passes it to `applyOnSave()` after
`hydrateObjectMetadata()`. `prepareObjectForCreation()` (~4271) passes `null`. The
rule:

1. Schema has no `objectSlugField` → legacy branch (D8).
2. `$originalSlug` is non-empty → keep it. If a client slug was sent and differs, log the
   "ignored, frozen" warning (D7).
3. Otherwise compute the candidate (D3). If it is `null`, there is no slug.
4. Run the uniqueness check (D4). A free candidate is written. A taken one goes to the
   suffix rule (D5), or the object stays without a slug and the collision is logged.

"Freed slug" needs no code of its own. Step 3 runs for every object without a slug on
every save, so once the holder is deleted, the next save of the waiting object takes the
slug. Nothing is triggered by the delete itself, so a delete never rewrites another
object.

### D3 — Candidate: client slug first, else `objectSlugField`; one slugify routine

`candidate()` returns the client slug (`@self.slug`, else top-level `slug`) as sent when
it is non-empty. Otherwise it reads `objectSlugField` through
`MetadataHydrationHandler::extractMetadataValue()`, which supports dot paths and the
template syntax the other `object*Field` keys use, and slugifies the result with
`MetadataHydrationHandler::createSlug()`. That routine is the one that produced every
slug stored today (it ran last), so existing slugs and new slugs come out the same.
`SaveObject::generateSlug()` / `createSlug()` (50-character cut, `-<time()>`) and
`MetadataHydrationHandler::generateSlug()` are removed (ADR-011: one implementation).
`setDefaultValues()` no longer adds `slug` to the data, which removes the
`@self.relations.slug` entry and the "Discarding … slug" warning.

### D4 — Uniqueness check: one indexed query, live rows, no access filter, self excluded

Two new `MagicMapper` methods, both selecting `_uuid` / `_slug` only:

```php
// single save: up to $limit uuids holding $slug, excluding $excludeUuid
public function findSlugHolders(Register $register, Schema $schema, string $slug,
    ?string $excludeUuid, int $limit = 1): array;

// bulk + backfill: holders of any of $slugs, and the stored slug of each of $uuids
public function findSlugState(Register $register, Schema $schema, array $slugs,
    array $uuids): array; // ['holders' => [slug => [uuid…]], 'stored' => [uuid => ?slug]]
```

Both use `WHERE _slug = ? / IN (…) AND _deleted IS NULL` on the register+schema table,
which the existing `_slug` index serves. They apply **no** RBAC or multitenancy filter.
Uniqueness is a property of the data, not of the caller, and a caller who cannot see the
holder must still not take its slug. The holder's uuid only reaches the server log, never
the API response, so nothing leaks. Soft-deleted rows (`_deleted IS NOT NULL`) do not
hold a slug, matching the read path, which also excludes them. `findSlugState()`
splits the `IN` lists into chunks of 1,000 values.

### D5 — `objectSlugSuffix`: UTC `YmdHis`, only on collision

With `objectSlugSuffix: true`, a taken candidate becomes
`<base>-<YYYYMMDDHHMMSS>`, with the time from `OCP\AppFramework\Utility\ITimeFactory`
in UTC. `<base>` is cut to `255 - 15` characters and any trailing hyphen is trimmed. The
suffixed value gets one more uniqueness check. If it is also taken (same second, same
title), the object stays without a slug and the collision is logged. A candidate that is
free gets no suffix, so the first object keeps the reproducible slug.

*Alternatives rejected:* always suffixing (no object would ever have a reproducible
slug, which is what #1592 needs); a counter `-2`, `-3` (the issue asks for a date-time,
and a counter derived from the current holders is order-dependent across bulk and
backfill); the old Unix-epoch `-<time()>` (not readable, and it is the variant GW4
retires).

### D6 — Bulk: one pass per register+schema group, plus a database-level freeze guard

In `SaveObjects::saveObjects()`, after preparation (`prepareSingleSchemaObjectsOptimized()`
/ `prepareObjectsForBulkSave()`) and before chunk processing, rows are grouped by
register+schema and `ObjectSlugHandler::applyToBatch()` runs once per group:

1. Compute each row's candidate (D3) from its business data and `@self.slug`.
2. One `findSlugState()` call for all candidates and all row uuids of the group.
3. Walk the rows **in batch order**: a stored non-empty slug is kept (freeze). Otherwise
   the candidate is checked against the holders (excluding the row itself) and against
   the slugs already assigned earlier in this batch, then D5 applies.
4. Write the decision into `@self.slug`, explicitly `null` when there is none.

`MagicBulkHandler::withDisplayColumns()` must then read `@self.slug` with
`array_key_exists`, not `??`. Otherwise a deliberate `null` falls through to a top-level
`slug` business property. `applyHydratedMetadata()` stops copying the slug.

For schemas with `objectSlugField`, the upsert's `_slug` clause becomes
`"_slug" = COALESCE(NULLIF("<table>"."_slug", ''), EXCLUDED."_slug")` on PostgreSQL and
`` `_slug` = COALESCE(NULLIF(`_slug`, ''), VALUES(`_slug`)) `` on MySQL/MariaDB. Step 3
already keeps the stored slug, and this guard also covers a row whose slug was written
between the prefetch and the upsert. Schemas without `objectSlugField` keep the plain
`EXCLUDED._slug` (BC1).

This makes the bulk outcome equal to a sequence of single saves in batch order (BC6),
with two queries per group instead of one per row (ADR-009 rule 1).

### D7 — Logging: one warning per event, every identifier in the message itself

Each line is a `warning` on the `openregister` logger. The message carries every
identifier, so it reads on its own in `nextcloud.log` and in `occ log:tail`. The same
values are in the context array for structured search. Templates:

| Event | Message |
|---|---|
| collision | `[ObjectSlug] Slug "<slug>" is already held by object <holder-uuid> in register <register-slug> (<id>) / schema <schema-slug> (<id>); object <object-uuid> was saved without a slug` |
| suffix also taken | same as collision, with `(suffixed "<slug>-<datetime>" also taken)` |
| client slug ignored | `[ObjectSlug] Ignored client slug "<sent>" for object <object-uuid> in register … / schema …: slug "<frozen>" is frozen` |
| ambiguous lookup | `[ObjectSlug] Identifier "<identifier>" is ambiguous in register … / schema …: matches objects <uuid-a>, <uuid-b>` |
| backfill duplicate | `[ObjectSlug] Duplicate slug "<slug>" in register … / schema … held by objects <uuid-a>, <uuid-b>[, …]` |

Context keys: `app`, `path` (`single` / `bulk` / `backfill` / `lookup`), `registerId`,
`registerSlug`, `schemaId`, `schemaSlug`, `slug`, `objectUuid`, `holderUuid` /
`uuids`. Example values in tests use the nil uuid
`00000000-0000-0000-0000-000000000000` and its siblings.

### D8 — Schemas without `objectSlugField` take the legacy branch

`applyOnSave()` with no `objectSlugField` does exactly what `setSelfMetadata()` does today:
a non-empty client slug is set, overwriting any stored one, with no query and no log.
`applyToBatch()` leaves `@self.slug` as the bulk path sets it today. This keeps BC1 and
BC5 literal for every schema that has not opted in, which today is every schema whose
configuration came through the API.

### D9 — No unique index, no migration

A partial unique index on `(_slug) WHERE _deleted IS NULL` per magic table would close
the race in Risks, but it fails on every table that already holds a duplicate (BC2
forbids touching them). It would also turn GW2's "save without a slug" into a 500 under
concurrency. The rule is enforced in the pipeline. The backfill and the 409 lookup make
any leftover duplicate visible.

### D10 — Ambiguity check stays in `findInRegisterSchemaTable()`; `show()` maps it to 409

`findInRegisterSchemaTable()` already detects the second row. It now reads `_uuid` from
both rows, logs the ambiguous-lookup warning (D7), and throws
`MultipleObjectsReturnedException` with a message that names the identifier but no
uuids. `ObjectsController::show()` adds a `catch (MultipleObjectsReturnedException)` that
answers `409` with the ADR-050 error envelope `{"message": "Identifier \"<identifier>\" matches more than one object in this register and schema", "error": "ambiguous-identifier"}`.
Other controllers that use `HandlesExceptionsTrait` already map the exception to 409.
`ObjectService::find()` only catches `DoesNotExistException`, so it passes the exception
through, and its uuid-only cross-schema retry is unaffected. Uuid and numeric-id lookups
cannot be ambiguous within one table (BC3).

*Alternative rejected:* checking in `ObjectService` (the plan's ~6321). The service does
not see the rows, so it would need a second query. The mapper already holds both rows.

### D11 — `occ openregister:backfill-slugs`

```
occ openregister:backfill-slugs <register> <schema> [--reslug] [--dry-run]
```

- `<register>`, `<schema>`: id, uuid or slug. The schema must be in the register.
- Exit `1` with a message when either does not resolve or the schema has no
  `objectSlugField`. Otherwise exit `0`, also when duplicates were reported.
- Thin command `lib/Command/BackfillObjectSlugsCommand.php`, registered in
  `appinfo/info.xml`. The logic lives in `lib/Service/Object/ObjectSlugBackfillService.php`
  so it can be unit-tested.
- **Duplicate report first:** one query
  `SELECT _slug, _uuid … WHERE _deleted IS NULL AND _slug IN (SELECT _slug … GROUP BY _slug HAVING COUNT(*) > 1)`.
  Each duplicate goes out as a console line and as a backfill-duplicate warning (D7). In
  default mode these rows are not touched.
- **Default mode:** pages over live rows with an empty slug, oldest first
  (`_created`, then `_id`, keyset pagination, 500 rows a page). Each row gets
  `ObjectSlugHandler::candidate()`, then the uniqueness and suffix rules against an
  in-memory set of all live slugs in the table, loaded once and updated as slugs are
  assigned.
- **`--reslug`:** computes the target slug of every live row oldest first. Every stored
  slug stays reserved for its holder until the holder moves, so no object is given a
  slug another object still carries. A row whose source is empty keeps its slug. A row
  whose new slug is held by another object keeps its current slug (reported as kept
  because of a collision) or, with the suffix on, gets the suffixed slug. The walk
  repeats until nothing moves, so an object waiting for another to vacate a slug is
  placed in a later pass and a second run finds nothing to do. The plan is written in
  transactions of 500 rows.
- **`--dry-run`:** prints the plan (`<uuid>: "<old>" -> "<new>"`) and writes nothing.
- **Writes:** `MagicMapper::updateSlugColumn(Register, Schema, string $uuid, ?string $slug)`
  issues `UPDATE <table> SET _slug = ? WHERE _uuid = ?` and evicts the object from the
  object cache. It does not bump `_version`, write a per-object audit-trail entry or
  dispatch an object event, the same as `openregister:backfill-system-owner`. The slug is
  derived metadata, and a few thousand events from one maintenance run would fan out to
  every listener. Because it replaces a business-visible identifier, every non-dry run
  writes one audit-trail entry (`AuditTrailMapper::createSlugBackfillEntry`) with the
  old to new list of that run.
- **Summary line:** `scanned=<n> assigned=<n> changed=<n> collisions=<n> kept=<n> unchanged=<n> duplicates=<n> skipped=<n>`.
- **Idempotent:** default mode only selects empty slugs, and in `--reslug` a second run
  computes the same plan, which is all "unchanged".

*Alternative rejected:* a repair step on `occ upgrade`. That writes data without an
operator choosing the register, and BC2 forbids that.

### D12 — Schema editor: the controls live in `@conduction/nextcloud-vue`

The configuration tab is a nextcloud-vue component, so the UI change is a PR on
`ConductionNL/nextcloud-vue`:

- `CnSchemaConfigurationTab.vue`: an `NcSelect` bound to
  `schema.configuration.objectSlugField` (`:options="propertyOptions"`, clearable,
  label "Object slug field", placeholder "Select a property to build object slugs
  from"), placed after "Object summary field". Below it an `NcCheckboxRadioSwitch` bound
  to `objectSlugSuffix` ("Append a date-time suffix when a slug is already taken"),
  disabled while no slug field is chosen. A short `NcNoteCard`: "A slug does not change
  once it is set. After switching the slug field, existing slugs are rewritten with the
  backfill command."
- `CnSchemaFormDialog.vue`: the configuration defaults (~333, ~596, ~622) gain
  `objectSlugField: ''` and `objectSlugSuffix: false`.
- New strings go into nextcloud-vue's own `t('nextcloud-vue', …)` catalogue.

OpenRegister then bumps `@conduction/nextcloud-vue` in `package.json`, adds both keys to
`src/entities/schema/schema.ts` (type, constructor default, zod `optional()`) and to the
configuration defaults in `src/store/modules/schema.js` (~363). An empty
`objectSlugField` is stored as `null` by the backend (D3 scenario "empty switches off").

## Backwards-compatibility analysis

| Rule | How it holds | Test |
|---|---|---|
| BC1 schemas without `objectSlugField` unchanged | D8 legacy branch; no query, no log; the upsert clause is unchanged for those schemas | single + bulk save on such a schema: slug `null`, no `[ObjectSlug]` log; client slug overwrites as before |
| BC2 existing slugs incl. duplicates and `-<timestamp>` variants kept | freeze (D2) keeps any stored non-empty slug; no migration, no index (D9); backfill default mode skips non-empty slugs | save each of two duplicates and a `-1700000000` slug, all unchanged |
| BC3 uuid / numeric id lookups unchanged | D10 only adds logging and 409 mapping for an identifier that already matched two rows | find by uuid and by id on objects that share a slug, both return 200 |
| BC4 new config keys optional | absent `objectSlugSuffix` = `false`; an older OpenRegister drops unknown keys (current behaviour); an export without them imports equal | import an export without the keys and compare the configuration |
| BC5 client slug accepted | D3 client slug first; legacy branch for non-opted-in schemas | create with top-level `slug` on an opted-in schema |
| BC6 bulk = single | D6 batch-order walk using the same handler | same input through both paths, compare slugs |

Deliberate behaviour changes, only for schemas that set `objectSlugField`, recorded in
`CHANGELOG.md`:

- GW1: a rename no longer changes the slug. Use `--reslug`.
- GW2: a collision leaves the new object without a slug.
- GW3: a duplicated slug answers 409 on lookup instead of an unhandled error.
- GW4: the `-<time()>` suffix that never took effect becomes the opt-in
  `objectSlugSuffix`.

Because `objectSlugField` could not be stored through the API before this change, GW1–GW3
only reach schemas that had it written into the database by hand, or that get it from
now on.

## Seed Data (ADR-001)

Not applicable: this change adds no OpenRegister schemas or registers, so there is
nothing to seed. All fixtures are built inside PHPUnit.

## Declarative-vs-imperative decision (ADR-031)

Imperative: slug generation, uniqueness and freezing are part of OpenRegister's own save
pipeline and lookup core, below the layer `x-openregister-*` dialects configure. The
schema opts in with plain configuration keys, like `objectNameField`.

## Risks / Trade-offs

- **[Race] Two concurrent saves with the same candidate both pass the check** → both
  hold the slug. Mitigation: rare (needs the same title in the same register + schema
  within milliseconds). The 409 lookup and the backfill duplicate report make it visible.
  An index is ruled out by D9.
- **[Restore] Restoring a soft-deleted object whose slug was taken in the meantime.**
  `MagicMapper::restoreObject()` is not part of the save pipeline, so it calls
  `ObjectSlugHandler::slugForRestore()` (resolved lazily through the container, as
  MagicMapper needs it to exist first): a taken slug is replaced by the suffixed one when
  `objectSlugSuffix` is on and cleared otherwise, in the same UPDATE that clears
  `_deleted`, with the usual collision warning. The seed import (`ImportHandler`) goes
  through `ObjectSlugHandler::applyOnSave()` for the same reason.
- **[Re-import with new uuid] A reset-sync creates a new object while the old one still
  holds the slug** → the new object waits without a slug until the old one is deleted,
  then gets it on its next save or by the backfill (issue plan Q8, working assumption).
- **[GW3] Environments with existing duplicates** get 409 on URLs that "worked" by
  accident. Mitigation: run the backfill first. Its duplicate report is the work list.
- **[Business property named `slug`]** A schema that declares its own `slug` property
  feeds it into the client-slug branch today. That stays as it is. With
  `objectSlugField` set, that value now also obeys freeze and uniqueness.
- **[Cross-repo UI]** The editor controls depend on a nextcloud-vue release. The
  backend, API and backfill work without it, and the UI task can land later.
- **[Removed tests]** Tests that call the removed `generateSlug()` / `createSlug()` methods
  are deleted or rewritten: `tests/Unit/Service/ObjectHandlers/SaveObjectAdditionalTest.php`
  (~515–560), `tests/Unit/Service/Object/SaveObjectDeepTest.php` (~616–650) and
  `tests/Service/BulkAndHandlersIntegrationTest.php` (~491–575). Coverage moves to
  `ObjectSlugHandlerTest`.

## Migration Plan

No schema or data migration. Deploy order per environment: update OpenRegister. Schemas
keep working (BC1–BC6). Set `objectSlugField` on the schemas that need slugs, through the
editor or an import. Run `occ openregister:backfill-slugs <register> <schema> --dry-run`,
read the duplicate report, then run it without `--dry-run`. Rollback: revert the app
version. Slugs written in the meantime are ordinary column values the older version
reads as they are, and an older version drops `objectSlugSuffix` from the configuration
without error (BC4).

## Test strategy

- **Unit (`tests/Unit/Service/Object/SaveObject/ObjectSlugHandlerTest.php`)**, mapper
  mocked: candidate (client first, field, empty, template path), free → set, taken →
  `null` + warning text and context, suffix (fixed `ITimeFactory`, truncation to 255,
  suffix also taken), freeze (rename, other field, differing client slug + warning),
  freed slug, legacy branch for no `objectSlugField`, batch: two equal titles, existing
  holder, stored slug kept, row excluded from its own check.
- **Unit `Schema`:** both keys persist, invalid `objectSlugSuffix` dropped alone, empty
  `objectSlugField` → `null`.
- **Unit `MagicMapper` / `MagicBulkHandler`:** `findSlugHolders()` / `findSlugState()` SQL
  shape (live rows only, no access filter, chunked `IN`). Upsert `_slug` clause with and
  without `objectSlugField` on both platforms. `withDisplayColumns()` honours an explicit
  `null`. `findInRegisterSchemaTable()` logs both uuids and throws.
- **Unit `ObjectsController::show()`:** `MultipleObjectsReturnedException` → 409 JSON
  without uuids.
- **Unit `ObjectSlugBackfillService`:** default fill, idempotent second run, duplicates
  reported and untouched, `--reslug` after a field switch, `--reslug` with an empty
  source, `--dry-run` writes nothing, refusal without `objectSlugField`.
- **Integration (`tests/Service/…IntegrationTest.php`, real database):** the reproduction
  table from the proposal, end to end on a magic table: create A/B, rename A, GET by slug,
  bulk 2 × "Bulk Item". Plus BC1–BC6, one test each.
- **Rig (NC 35):** rerun the reproduction script and record the side-by-side result in
  the issue plan.
- Every test asserts the **resulting slug value and the uuid it belongs to**, not only that
  a call succeeded. Today's code also "succeeds".
