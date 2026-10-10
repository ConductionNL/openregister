# objects-crud Specification

## Purpose
Create, read, update, delete and list objects through the objects API, with list limits clamped and the total count optional, so a list call stays bounded.

## Requirements

### Requirement: List page size is bounded by a hard maximum

Every object list/search endpoint SHALL clamp a client-supplied NUMERIC `_limit`
to a hard maximum. A number above the maximum SHALL be reduced to the maximum;
it SHALL NOT cause the server to load an arbitrarily large result set.

The bound SHALL be escapable only by an EXPLICIT unlimited request (see "`_limit`
supports an explicit unlimited value"). An oversized number is not such a
request: it is a caller who has not considered the size of the result set, and
the clamp exists for exactly that caller.

#### Scenario: Oversized limit is clamped

- **WHEN** a client requests a list with `_limit` far above the maximum (e.g.
  `_limit=1000000`)
- **THEN** at most `MAX_PAGE_SIZE` rows are loaded and returned

#### Scenario: An explicit unlimited is not clamped

- **WHEN** a client requests a list with `_limit=false`
- **THEN** no `LIMIT` is applied and every matching row is returned

### Requirement: `_limit` supports an explicit unlimited value

`_limit` SHALL accept `false`, `null`, `0`, `all`, `unlimited` and `none` (any
case) to mean "no limit", and every read path SHALL agree on that meaning.

A value that is not a usable row count — a non-numeric string, or a negative
number — SHALL be treated as unlimited rather than as zero. It SHALL NOT
produce an empty result set.

Rationale: before this requirement the same `_limit=0` meant three different
things depending on which path served the request (no rows on the single-schema
path, one row on the cross-schema path, the provider default against an
external database), and `(int)"abc"` was `0`, so a typo returned a confidently
empty list with HTTP 200.

#### Scenario: Explicit unlimited returns every row

- **WHEN** a client requests a list with `_limit=false` (or `0`, or `all`)
- **THEN** the query carries no `LIMIT` clause
- **AND** every matching row is returned

#### Scenario: An unusable limit does not empty the result set

- **WHEN** a client requests a list with `_limit=abc` or `_limit=-5`
- **THEN** the value is treated as unlimited
- **AND** the response is NOT an empty list

### Requirement: The total-count query is optional

A client SHALL be able to request a list without the total count. When the total
is opted out, the endpoint SHALL NOT execute the COUNT query and SHALL return
`total: null`.

#### Scenario: Count can be skipped

- **WHEN** a client requests a list with `_count=false`
- **THEN** no COUNT query is executed
- **AND** the response reports `total: null`

#### Scenario: Default behaviour includes the total

- **WHEN** a client requests a list without the count flag
- **THEN** the total is computed and returned as before

### Requirement: Partial object updates are protected against lost updates

A partial update (PATCH) of an object SHALL apply optimistic concurrency
control. The object's version captured at read time SHALL be asserted at write
time; if the persisted object changed since it was read, the update SHALL be
rejected with HTTP 409 Conflict rather than overwriting the concurrent change.

#### Scenario: Concurrent PATCHes do not lose data

- **WHEN** two clients read the same object version and each PATCHes a different
  field
- **AND** the first PATCH commits successfully
- **THEN** the second PATCH is rejected with HTTP 409
- **AND** the first client's field is not overwritten or lost

#### Scenario: Conditional update via If-Match

- **WHEN** a client sends a PATCH with an `If-Match` value that no longer matches
  the object's current version
- **THEN** the request is rejected with HTTP 409 and the current version is
  reported

#### Scenario: Non-conflicting PATCH succeeds

- **WHEN** a client PATCHes an object whose version has not changed since it was
  read
- **THEN** the update is applied and a new version/etag is returned

### Requirement: List/search relation resolution is batched, not per-row

When rendering a page of objects with `_extend`, related objects SHALL be
preloaded in a bounded number of queries for the whole page, not fetched
per row. The list/search render path SHALL use the same batch-preload used by
`renderEntities()`.

#### Scenario: Extended list does not N+1

- **WHEN** a client requests a page of objects with `_extend` on a relation
- **THEN** the related objects are fetched in O(1) batched queries for the page
- **AND** the number of relation queries does not grow with the page size

### Requirement: Schema-derived and request-invariant values are computed once

Schema-derived and request-invariant values SHALL be computed once per
schema/request and reused across all objects processed in that pass. This
includes property-authorization presence, computed-property presence, the cleaned
validation schema, the compiled validator, and the current user's group ids and
admin status.

#### Scenario: Wide-schema list does per-schema work once

- **WHEN** a page of N objects of one schema is rendered
- **THEN** each schema-derived value is computed once, not N times
- **AND** the current user's group ids are resolved once for the request

#### Scenario: Bulk validation reuses one validator

- **WHEN** many objects of one schema are validated in a bulk operation
- **THEN** the cleaned schema and the validator/format resolvers are constructed
  once, not per object

### Requirement: Field selection narrows the SQL projection

When a request specifies `_fields`, the database query SHALL select only those
columns plus the metadata columns required for hydration, rather than selecting
all property columns and trimming in PHP.

#### Scenario: Narrow field request transfers few columns

- **WHEN** a client requests `_fields=id,name`
- **THEN** the generated SQL selects only those fields plus required metadata
  columns

### Requirement: Preserve JSON object key order on write and read (REQ-OBJ-KO-01)

The object create/update path MUST preserve the insertion order of keys within
any JSON object-typed property exactly as submitted, through validation,
storage, and read-back. The write path MUST NOT reorder,
alphabetise, or canonicalise object keys; default values for absent keys MUST be
appended after the submitted keys without reordering existing ones. The read
serializer MUST return object keys in stored order. Preserving key order MUST
NOT alter the PUT-semantic carry-forward of unchanged fields.

#### Scenario: Drag-reorder persists across save

- **GIVEN** an object with an object-keyed property `{ "a": 1, "b": 2, "c": 3 }`
- **WHEN** the client reorders it to `{ "c": 3, "b": 2, "a": 1 }` and PUTs the
  object
- **THEN** reading the object back returns the keys in the order
  `c`, `b`, `a`
- **AND** a sibling property that was not changed retains its value.

#### Scenario: No implicit reordering

- **WHEN** an object with an object-keyed property is saved unchanged
- **THEN** the stored and returned key order is identical to the submitted
  order, with no alphabetisation or canonicalisation applied.

### Requirement: REQ-ATOMIC-001 An atomic batch is written whole or not at all

A bulk save with `atomic: true` SHALL write every row or none. A refused row SHALL roll back the batch, the answer SHALL name its index and reason, and no event or webhook SHALL be sent for a rolled back batch.

#### Scenario: one bad row stops the batch

- **GIVEN** an atomic batch of three rows whose third fails validation
- **WHEN** a client posts it to the bulk endpoint
- **THEN** no row is stored, the answer names row index 2, and no webhook fires
- @e2e exclude {transaction semantics asserted on a real SQLite transaction in tests/Unit/Controller/BulkAtomicSaveTest.php}

### Requirement: REQ-RFCE-001 The record form gives each declared field its own editor

The record form SHALL render a property with an `enum` (or a `oneOf` of constants) as a select of the declared values, a file property as a file picker, and a property of a register that declares languages as one input per language.

#### Scenario: an enum field is a choice list

- **GIVEN** a schema property `status` with enum `open`, `closed`
- **WHEN** a record editor opens the edit dialog of a record on /tables
- **THEN** the `status` field is a select offering `open` and `closed`, and saving sends the chosen value
- @e2e exclude {the editor choice is asserted in src/services/propertyEditor.spec.js; the 3,500-line record modal is not mounted by the jest setup}

#### Scenario: a translatable field has a tab per language

- **GIVEN** a register with languages `nl` and `en` and a schema property `title`
- **WHEN** a record editor opens the edit dialog
- **THEN** the `title` field shows an input for `nl` and one for `en`, and saving stores both variants
- @e2e exclude {asserted in src/services/propertyEditor.spec.js; TranslationFieldEditor has its own src/components/i18n/TranslationFieldEditor.spec.js}

### Requirement: REQ-RFCE-002 A cell in the records list can be edited in place

A user with update rights on a record SHALL be able to edit a scalar field directly in its cell on the records list. The save SHALL use the same PATCH as the record form, and a refused save SHALL show the server message in the cell and keep the old value.

#### Scenario: a record editor fixes a value in the list

- **GIVEN** a records list on /tables showing a text column `reference`
- **WHEN** a record editor double clicks the cell, types a new value and presses Enter
- **THEN** the record is saved with the new value and the cell shows it
- @e2e exclude {asserted in src/components/tables/EditableCell.spec.js and src/views/search/SearchIndex.spec.js}

#### Scenario: a reader cannot edit

- **GIVEN** a user with read rights only
- **WHEN** they double click a cell
- **THEN** the record modal opens as before and no inline editor appears
- @e2e exclude {asserted in src/components/tables/EditableCell.spec.js and tests/Unit/Service/Object/RenderObjectUpdateRightTest.php}

### Requirement: A single-object read renders exactly once

The single-object read path (`GET .../objects/{register}/{schema}/{id}`) SHALL execute exactly one
render pass per response. The retrieval step (`ObjectService::find()`) MUST be able to return the
raw entity without rendering when the caller is itself the render site, while still performing
object retrieval, the cross-schema uuid fallback, the per-object read permission check, and AVG
read logging. Server-side writeOnly redaction and property read-authorization stripping MUST be
applied by that single render pass on the response path — never zero times, never twice.

#### Scenario: show() is the single render site

- **WHEN** a client requests a single object via the objects API
- **THEN** the controller obtains the raw entity without a render pass (`find(_render: false)`)
- **AND** renders it exactly once with the request's extend/filter/fields/unset parameters
- **AND** writeOnly properties are absent from the response body

#### Scenario: Internal callers keep rendered reads

- **WHEN** any other caller invokes `ObjectService::find()` without the `_render` argument
- **THEN** the returned entity is rendered exactly as before this change

### Requirement: Single reads resolve inverse properties through the batched machinery

When a single-entity render extends an `inversedBy` property, the system SHALL resolve the
referencing objects through the same schema-targeted batched lookup the list path uses
(`findByRelationBatchInSchema` against the target schema's magic table), populating the inverse
relation cache and serving the properties from it. The preload MUST cover ALL of the schema's
inverse properties — not only the extended ones — because a single read resolves every inverse
property once any one of them is extended; a partial preload would silently empty the others in
the response. The generic cross-table reverse-reference scan (`findByRelation`) SHALL only run as
a resilience fallback when the batched preload cannot populate the cache (e.g. an unresolvable
target schema reference). For the extended inverse property, a single read MUST produce the same
value as a list read of the same object with the same extend.

#### Scenario: Single read uses the schema-targeted batch lookup

- **GIVEN** a schema with an `inversedBy` property and one referencing object in the target schema
- **WHEN** the object is rendered individually with the inverse property extended
- **THEN** the referencing object is found via the schema-targeted batched lookup
- **AND** no cross-table reverse-reference scan is executed

#### Scenario: Single and list reads agree on the extended inverse property

- **WHEN** the same object is rendered once via the single-read path and once via the list path,
  both extending the same inverse property
- **THEN** both renders return an identical value for that inverse property

#### Scenario: Non-extended inverse properties keep their resolved values on single reads

- **GIVEN** a schema with two `inversedBy` properties, each with a referencing object
- **WHEN** the object is rendered individually extending only one of the two inverse properties
- **THEN** the extended inverse property contains its referencing object
- **AND** the other inverse property is also populated with its referencing object — it is not
  emptied by the batched preload

### Requirement: uuid scope resolution is cached per request

The system SHALL keep a request-scoped cache of uuid → resolved (register, schema) contexts.
After a uuid has been resolved once in a request — directly or via the cross-schema fallback —
subsequent `find()` calls for that uuid SHALL target the resolved register/schema directly instead
of re-missing the caller-supplied stale scope and re-running the cross-table search. The cache MUST
NOT change fallback semantics: the first stale-scope read still resolves via the cross-table
fallback, a cache entry that no longer resolves is invalidated and falls back, and permission
checks run on every call.

#### Scenario: Repeated stale-scope read skips the cross-table scan

- **GIVEN** a uuid already resolved once in this request via the cross-schema fallback
- **WHEN** the same uuid is read again under the same stale register/schema scope
- **THEN** exactly one scoped lookup runs, targeting the object's true register and schema
- **AND** no cross-table search is executed

#### Scenario: Stale cache entry falls back safely

- **GIVEN** a cached uuid scope whose object has since moved or been deleted
- **WHEN** the cached scoped lookup misses
- **THEN** the cache entry is invalidated and the existing cross-table fallback runs unchanged

### Requirement: A refused write names the values that conflict (REQ-CSO-001)

When a write is refused for a version mismatch, the HTTP 409 body SHALL
list, for every conflicting property, the value the caller sent, the value
the caller read, and the value now stored, together with the actor and the
moment of the change that caused the refusal. A property SHALL be listed as
conflicting only when the caller changed it and another write changed it
since the caller read it.

#### Scenario: the second person is told what the first one wrote

- **GIVEN** two callers who read the same object, the first saving `status` and the second saving `status` and `summary`
- **WHEN** the second save is refused
- **THEN** the body lists `status` with the value sent, the value read and the value stored
- **AND** `summary` is not listed

#### Scenario: an untouched property is not a conflict

- **GIVEN** a caller whose write touches only `summary` and a concurrent write that changed `status`
- **WHEN** the caller's write is refused for the version mismatch
- **THEN** `status` is not listed as a conflicting property of that write
- @e2e exclude {response shape, covered by unit tests}

#### Scenario: the refusal names who changed it

- **GIVEN** a refused write
- **WHEN** the body is read
- **THEN** it names the actor and the moment of the change that caused the refusal

### Requirement: The conflict body discloses no more than a read would (REQ-CSO-002)

The conflict body SHALL be filtered by field-level security for the calling
principal. A conflicting property the caller may not read SHALL be named as
conflicting with no values attached.

#### Scenario: a restricted property conflicts without showing itself

- **GIVEN** a conflicting property restricted to a group the caller is not in
- **WHEN** the caller's write is refused
- **THEN** the property is named as conflicting
- **AND** no sent, read or stored value is present for it
- @e2e exclude {field filter, covered by unit tests}

### Requirement: Every write path asserts the expected version (REQ-CSO-003)

The version assertion SHALL be made in the save pipeline, so that a full
replace asserts exactly as a partial update does. A write carrying an
expected version or an `If-Match` value that no longer matches SHALL be
refused with HTTP 409 and the conflict body. A write carrying neither SHALL
behave as it does today. A refused write SHALL leave an audit entry naming
the expected and the current version, the actor and the properties that
conflicted.

#### Scenario: a full replace from a stale read is refused

- **GIVEN** a caller who read an object, and a concurrent write that changed it
- **WHEN** the caller sends a full replace with the version they read
- **THEN** the write is refused with HTTP 409 and the conflict body
- **AND** the stored object is unchanged

#### Scenario: a write without an expected version is unchanged

- **GIVEN** a client that sends no expected version and no `If-Match`
- **WHEN** it writes an object another caller changed
- **THEN** the write behaves exactly as before this change

#### Scenario: the refusal is on the audit trail

- **GIVEN** a refused write
- **WHEN** the object's audit trail is read
- **THEN** it holds an entry naming the expected version, the current version and the actor
- @e2e exclude {audit entry, covered by unit tests}

### Requirement: A write can create or update by a declared key in one call

`POST /api/objects/{register}/{schema}` SHALL accept `_upsertOn=<constraint>` naming a uniqueness constraint the schema declares with action `refuse`, including the legacy `unique` key named by its properties joined with `+`. Open Register SHALL read that constraint's property values from the body and match them under the caller's RBAC and organisation. With no match it SHALL create the record and answer 201. With one match it SHALL update that record with the body and answer 200. With more than one match it SHALL write nothing and answer 409 listing the matches.

#### Scenario: an integration creates then updates a case by its number

- **GIVEN** schema `zaak` in register `zaken` declares the `refuse` constraint `zaaksleutel` over `gemeentecode` and `zaaknummer`, and holds no record with `0363` and `Z-2026-0042`
- **WHEN** a signed-in integration posts `{"gemeentecode": "0363", "zaaknummer": "Z-2026-0042", "omschrijving": "Kapvergunning"}` to `POST /api/objects/zaken/zaak?_upsertOn=zaaksleutel`
- **THEN** the response is 201 with the new record
- **AND WHEN** it posts the same key with `"omschrijving": "Kapvergunning Dorpsstraat"`
- **THEN** the response is 200, the same uuid is returned, and the record carries the new `omschrijving`
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/upsert-on-key.spec.ts}

#### Scenario: a key that matches two records writes nothing

- **GIVEN** two records in `zaak` that both carry `0363` and `Z-2026-0001`, left from before the constraint was declared
- **WHEN** a signed-in integration posts that key to `POST /api/objects/zaken/zaak?_upsertOn=zaaksleutel`
- **THEN** the response is 409 listing both uuids
- **AND** neither record changes
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/upsert-on-key.spec.ts}

### Requirement: Only a declared refuse constraint can be the key

Open Register SHALL answer 400 when `_upsertOn` names no declared constraint, names a constraint with action `report`, or the body lacks a value for one of the constraint's properties. The answer SHALL name the problem and list the schema's `refuse` constraints. `_upsertOn` together with `_failIfExists` SHALL answer 400.

#### Scenario: an integration names a field instead of a constraint

- **GIVEN** schema `zaak` declares only the `refuse` constraint `zaaksleutel`
- **WHEN** a signed-in integration calls `POST /api/objects/zaken/zaak?_upsertOn=status`
- **THEN** the response is 400 with `refuseConstraints` `["zaaksleutel"]`
- **AND** no record is created or changed
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/upsert-on-key.spec.ts}

#### Scenario: a missing key value is named

- **GIVEN** the same schema
- **WHEN** a signed-in integration posts a body without `zaaknummer` to `POST /api/objects/zaken/zaak?_upsertOn=zaaksleutel`
- **THEN** the response is 400 naming `zaaknummer`
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/upsert-on-key.spec.ts}

### Requirement: An upsert never reveals or changes a record the caller cannot see

Open Register SHALL answer 401 to `_upsertOn` on an anonymous request. When the key is held by a record the caller cannot read, it SHALL answer 409 naming the constraint and SHALL NOT include that record's uuid. When the caller can read the matched record but may not change it, it SHALL answer 403. When the lookup cannot run, it SHALL answer 503 and write nothing.

#### Scenario: an anonymous form cannot overwrite a case by guessing its key

- **GIVEN** a public form that posts anonymously to `POST /api/objects/zaken/zaak`
- **WHEN** the request adds `?_upsertOn=zaaksleutel`
- **THEN** the response is 401 and no record is created or changed
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/upsert-on-key.spec.ts}

#### Scenario: a key held in another organisation is not named

- **GIVEN** a record with key `0363` and `Z-2026-0042` that belongs to an organisation the caller is not a member of
- **WHEN** a signed-in integration of another organisation posts that key with `_upsertOn=zaaksleutel`
- **THEN** the response is 409 naming the constraint `zaaksleutel`
- **AND** the body carries no uuid
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/upsert-on-key.spec.ts}

### Requirement: Concurrent upserts on one key produce one record

Open Register SHALL serialise upserts that carry the same register, schema, constraint and key values, so concurrent calls with one key create at most one record and update it for the rest. Upserts with different keys SHALL NOT wait on each other. A call SHALL wait only a short, bounded time for the key; a call that cannot have the key in that time SHALL answer 503 with a `Retry-After` header and SHALL write nothing, so a burst never holds a worker for long and a client that retries gets 200.

#### Scenario: twelve synchronisation workers send the same new case

- **GIVEN** no record carries key `0363` and `Z-2026-0099`
- **WHEN** twelve signed-in workers post that key with `_upsertOn=zaaksleutel` at the same moment
- **THEN** one response is 201 and every other response is 200, or 503 with a `Retry-After` header
- **AND** `zaak` holds exactly one record with that key

#### Scenario: a worker that cannot have the key in time is told when to retry

- **GIVEN** another call holds the key `0363` and `Z-2026-0099` for longer than the wait
- **WHEN** a signed-in worker posts that key with `_upsertOn=zaaksleutel`
- **THEN** the response is 503 with a `Retry-After` header
- **AND** nothing is written
- @e2e exclude {lock contention, not a page; covered by ObjectsControllerUpsertOnKeyTest::testAKeyAnotherCallHoldsIs503WithRetryAfter}
- @e2e exclude {concurrency, not a page; task 2.2 adds tests/Integration/UpsertOnKeyConcurrencyTest.php}
