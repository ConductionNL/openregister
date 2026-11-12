## ADDED Requirements

### Requirement: Schema configuration MUST accept and persist the object-slug keys

`Schema::validateConfigurationArray()` MUST accept `objectSlugField` as a string
configuration key, with the same rules as `objectNameField` (a string, `null`, or an
empty string that is stored as `null`). It MUST accept `objectSlugSuffix` as a boolean
configuration key, with the same rules as the other boolean keys (`true`, `false` or
`null`). Both keys MUST survive a schema save through the API and a configuration
import, because both paths go through `Schema::setConfiguration()`. An invalid value
for either key MUST drop only that key and keep the rest of the configuration, per the
per-key isolation that already applies to every configuration key. Both keys are
optional, and an absent `objectSlugSuffix` MUST behave as `false`.

#### Scenario: Schema save keeps objectSlugField and objectSlugSuffix
- **GIVEN** a schema save payload whose `configuration` holds `objectSlugField: "title"` and `objectSlugSuffix: true`
- **WHEN** the schema is saved through the schemas API
- **THEN** the stored configuration MUST contain `objectSlugField: "title"` and `objectSlugSuffix: true`
- **AND** a subsequent GET of the schema MUST return both keys

#### Scenario: Configuration import keeps objectSlugField
- **GIVEN** a configuration file whose schema declares `configuration.objectSlugField: "title"`
- **WHEN** the configuration is imported
- **THEN** the imported schema MUST carry `objectSlugField: "title"`

#### Scenario: An invalid objectSlugSuffix drops only that key
- **GIVEN** a schema save payload with `objectSlugField: "title"` and `objectSlugSuffix: "yes"`
- **WHEN** the schema is saved
- **THEN** `objectSlugSuffix` MUST be dropped
- **AND** `objectSlugField` and every other configuration key MUST be kept

#### Scenario: An empty objectSlugField switches slugs off
- **GIVEN** a schema save payload with `objectSlugField: ""`
- **WHEN** the schema is saved
- **THEN** the stored `objectSlugField` MUST be `null`

### Requirement: An object slug MUST come only from the configured slug field and MUST NOT be written into the object data

For a schema with `objectSlugField`, the slug candidate of an object MUST be the value
at that field path, converted by the single slugify routine
(`MetadataHydrationHandler::createSlug()`: lower case, spaces and underscores to
hyphens, characters other than `a-z`, `0-9` and `-` removed, repeated hyphens
collapsed, leading and trailing hyphens trimmed). When the field is absent or empty the
candidate MUST be `null` and the object MUST get no slug. There MUST be no fallback to
`name`, `title`, `label` or the schema title. The reserved value `@schema.name` is the
one other source a schema MAY choose (Robert Zondervan, 2026-10-26: a choice, not a
fallback): the candidate is then `<schema slug>-<object name>`, the object name being
the value of the schema's `objectNameField`, slugified on its own; a schema without a
slug contributes its slugified title. Without an `objectNameField`, without a name value,
with a name that slugifies to nothing (only punctuation or non-Latin letters) or without
a schema slug and title the candidate MUST be `null`; the schema part alone is never a
slug. Any candidate is cut to the width of the slug column without a trailing hyphen. The save pipeline MUST NOT add a `slug` key to the object
data. The slug is entity metadata only, so it never appears in `@self.relations` and
never causes a magic-mapper "Discarding … slug" warning.

#### Scenario: Slug derived from the configured field
- **GIVEN** a schema with `objectSlugField: "title"` and no object holding slug `hello-world` in its register and schema
- **WHEN** an object with `title: "Hello World"` is created
- **THEN** the object's `@self.slug` MUST be `hello-world`

#### Scenario: The schema-name source builds the slug from schema slug and object name
- **GIVEN** a schema with slug `convenanten`, `objectSlugField: "@schema.name"` and `objectNameField: "title"`
- **WHEN** an object with `title: "Hello World"` is created
- **THEN** the object's `@self.slug` MUST be `convenanten-hello-world`
- **AND** an object without a `title` value MUST get no slug
- **AND** an object whose `title` is `"!!!"` MUST get no slug, not `convenanten`

#### Scenario: Empty source field gives no slug
- **GIVEN** a schema with `objectSlugField: "title"`
- **WHEN** an object without a `title` value is created
- **THEN** the object's `@self.slug` MUST be `null`
- **AND** no fallback field or schema title MUST be used

#### Scenario: The slug is not stored as object data
- **GIVEN** a schema with `objectSlugField: "title"` that does not declare a `slug` property
- **WHEN** an object with `title: "Hello World"` is created and then updated
- **THEN** the object data MUST NOT contain a `slug` key
- **AND** `@self.relations` MUST NOT contain a `slug` entry
- **AND** no "Discarding … slug" warning MUST be logged

### Requirement: Object slugs MUST be unique within a register and schema, and a colliding slug MUST NOT be written

For a schema with `objectSlugField`, a slug MUST be held by at most one live
(not soft-deleted) object within the same register and schema, as far as the save
pipeline can enforce it. Before a slug is written, the pipeline MUST check whether
another live object in that register and schema holds it. The check MUST exclude the
object being saved, and MUST ignore the caller's RBAC and multitenancy scope, because
uniqueness does not depend on who saves. When `objectSlugSuffix` is not `true` and the
slug is taken, the object MUST be saved without a slug, the save MUST succeed, and a
warning MUST be logged that names the register, the schema, the slug, the uuid of the
object saved without it and the uuid of the object holding it. The same slug MAY exist
in a different schema or a different register. A soft-deleted object MUST NOT hold its
slug for this check. The same rules MUST apply when a soft-deleted object is restored,
when an object is moved or migrated to another register or schema (its slug is checked
against the target's live holders), and when a configuration's seed data creates an
object: a slug another live object holds by then MUST NOT be written, so the object is
restored, moved (or seeded) without a slug, or with the suffixed slug when
`objectSlugSuffix` is `true`, and the collision warning MUST be logged. A seed object's
client slug is its explicit `slug` only; its title MUST NOT be used as one, so a seed
without a `slug` gets the slug of the schema's configured source, as through the API.

#### Scenario: Second object with the same source value is saved without a slug
- **GIVEN** a schema with `objectSlugField: "title"` and `objectSlugSuffix` absent
- **AND** object A with `title: "Hello World"` holds slug `hello-world`
- **WHEN** object B with `title: "Hello World"` is created in the same register and schema
- **THEN** the save of B MUST succeed
- **AND** B's `@self.slug` MUST be `null`
- **AND** A's `@self.slug` MUST still be `hello-world`
- **AND** one warning MUST be logged naming the register, the schema, slug `hello-world`, B's uuid and A's uuid

#### Scenario: The same slug in another schema is not a collision
- **GIVEN** object A holds slug `hello-world` in register R and schema S1
- **WHEN** an object with `title: "Hello World"` is created in register R and schema S2, which also sets `objectSlugField: "title"`
- **THEN** the new object's `@self.slug` MUST be `hello-world`
- **AND** no collision warning MUST be logged

#### Scenario: A soft-deleted holder does not block the slug
- **GIVEN** object A holding slug `hello-world` has been soft-deleted
- **WHEN** object B with `title: "Hello World"` is created in the same register and schema
- **THEN** B's `@self.slug` MUST be `hello-world`

#### Scenario: Restoring an object whose slug was handed on
- **GIVEN** object A held slug `hello-world` and was soft-deleted
- **AND** object B with `title: "Hello World"` was then saved and took `hello-world`
- **WHEN** A is restored
- **THEN** A MUST be live again without a slug (with `hello-world-<YYYYMMDDHHMMSS>` when `objectSlugSuffix` is `true`)
- **AND** B MUST still hold `hello-world`, as the only live holder
- **AND** one warning MUST be logged naming the register, the schema, the slug, A's uuid and B's uuid

### Requirement: `objectSlugSuffix` MUST append a UTC date-time suffix to a colliding slug

The pipeline MUST write `<slug>-<YYYYMMDDHHMMSS>` (UTC, time of the save) instead of
the slug candidate when the schema sets `objectSlugSuffix: true` and the candidate is
held by another live object in the same register and schema. When that value is also
taken, the object MUST be saved without a slug and the collision warning MUST be
logged. A candidate that does not collide MUST be written without a suffix. The base
slug MUST be truncated so the suffixed value fits the 255-character slug column.
Without `objectSlugSuffix: true` no suffix is ever appended, so slugs are reproducible.

#### Scenario: Suffix on collision
- **GIVEN** a schema with `objectSlugField: "title"` and `objectSlugSuffix: true`
- **AND** object A holds slug `hello-world`
- **WHEN** object B with `title: "Hello World"` is created at the UTC time 2026-01-02 03:04:05
- **THEN** B's `@self.slug` MUST be `hello-world-20260102030405`

#### Scenario: No suffix without a collision
- **GIVEN** a schema with `objectSlugField: "title"` and `objectSlugSuffix: true`
- **AND** no object holds slug `hello-world`
- **WHEN** an object with `title: "Hello World"` is created
- **THEN** its `@self.slug` MUST be `hello-world`

#### Scenario: Default off gives reproducible slugs
- **GIVEN** a schema with `objectSlugField: "title"` and no `objectSlugSuffix` key
- **WHEN** an object with `title: "Hello World"` is created and no other object holds `hello-world`
- **THEN** its `@self.slug` MUST be exactly `hello-world`, with no suffix

### Requirement: An object slug once set MUST NOT be changed by a later save

A save MUST keep the slug of an object that already holds a non-empty slug, for a
schema with `objectSlugField`, whatever the payload contains: a changed source field, a
different client-supplied slug, or a payload without any slug. Only an object without a
slug gets one at save time. A set slug never changes afterwards, also not when
`objectSlugField` is switched: the switch applies to objects that have no slug yet, and
the backfill command has no rewrite mode (Robert Zondervan, 2026-10-23).

#### Scenario: Renaming the source field keeps the slug
- **GIVEN** a schema with `objectSlugField: "title"` and object A holding slug `hello-world`
- **WHEN** A is updated with `title: "Hello World Renamed"`
- **THEN** A's `@self.slug` MUST still be `hello-world`

#### Scenario: Updating another field keeps the slug
- **GIVEN** object A holding slug `hello-world`
- **WHEN** A is updated with a change to a field other than the slug field
- **THEN** A's `@self.slug` MUST still be `hello-world`

#### Scenario: An object without a slug gets one at its next save
- **GIVEN** a schema with `objectSlugField: "title"` and object A whose `@self.slug` is `null` while its `title` is `"Hello World"`
- **AND** no live object in the register and schema holds `hello-world`
- **WHEN** A is saved
- **THEN** A's `@self.slug` MUST be `hello-world`

### Requirement: A slug freed by deleting its holder MUST go to the next object that saves without a slug

An object that was left without a slug MUST receive it on its next save once the
object holding that slug is deleted (soft or hard), through the ordinary
"only an object without a slug gets one" rule. The delete itself MUST NOT change any
other object.

#### Scenario: The waiting object takes the freed slug on its next save
- **GIVEN** object A holds slug `hello-world` and object B with `title: "Hello World"` was saved without a slug
- **WHEN** A is deleted
- **AND** B is saved again
- **THEN** B's `@self.slug` MUST be `hello-world`

#### Scenario: The delete itself changes no other object
- **GIVEN** object A holds slug `hello-world` and object B was saved without a slug
- **WHEN** A is deleted
- **THEN** B's `@self.slug` MUST still be `null` until B is saved

### Requirement: A client-supplied slug MUST follow the same uniqueness and freeze rules

A slug a client sends in `@self.slug` or as top-level `slug` MUST stay accepted, so
integrations that set their own slugs keep working. For a schema with
`objectSlugField`, such a slug MUST take the place of the generated candidate and MUST
pass the same rules: it is written only when the object has no slug yet, it is subject
to the uniqueness check and to `objectSlugSuffix`, and a collision leaves the object
without a slug and logs the collision warning. A client-supplied slug that differs from
an existing frozen slug MUST be ignored and logged as a warning; the save MUST NOT fail.
The value MUST be normalised exactly like a generated slug (lower case, spaces and `_` become `-`, every other character outside `a-z0-9` and `-` is dropped without transliteration, at most 255 characters), so `Hello` and `hello` are one slug and `Zaak_2026-0042` is stored as `zaak-2026-0042`; a client slug that normalises to nothing MUST be treated as not sent and the slug then comes from `objectSlugField`. A frozen object MUST compare the normalised client slug with its stored slug, so re-sending the same slug in another case or format is not logged as ignored. Single and bulk save MUST compare slugs case-insensitively, so a collation that matches `Hello` to a stored `hello` cannot let both be written.

#### Scenario: A client slug that differs only in case collides in a bulk save
- **GIVEN** a schema with `objectSlugField: "title"` and object A holding `hello`
- **WHEN** a bulk save contains a new object with `@self.slug: "Hello"`
- **THEN** the new object MUST be saved without a slug and the collision warning MUST name A

#### Scenario: A free client slug is accepted on a new object
- **GIVEN** a schema with `objectSlugField: "title"` and no object holding `my-own-slug`
- **WHEN** an object is created with `@self.slug: "my-own-slug"` and `title: "Hello World"`
- **THEN** its `@self.slug` MUST be `my-own-slug`

#### Scenario: A colliding client slug is not written
- **GIVEN** object A holds slug `my-own-slug`
- **WHEN** object B is created in the same register and schema with `@self.slug: "my-own-slug"`
- **THEN** the save of B MUST succeed with `@self.slug` `null`
- **AND** the collision warning MUST name both uuids

#### Scenario: A client slug cannot overwrite a frozen slug
- **GIVEN** object A holds slug `hello-world`
- **WHEN** A is updated with `@self.slug: "something-else"`
- **THEN** A's `@self.slug` MUST still be `hello-world`
- **AND** a warning MUST be logged that the client slug was ignored because the slug is frozen

### Requirement: Bulk save MUST apply the same slug rules as a single save

`SaveObjects` MUST produce, for the same input and the same existing data, the same
slugs as a sequence of single saves in batch order. Concretely: the candidate comes
only from `objectSlugField` or a client-supplied slug; uniqueness is checked against
live objects in the register and schema and against slugs assigned earlier in the same
batch; a collision leaves the object without a slug and logs the collision warning, or
applies `objectSlugSuffix`; and a bulk update of an object that already holds a slug
keeps it. The existence check MUST be batched per register and schema, not run once per
object (ADR-009).

#### Scenario: Two objects with the same title in one batch
- **GIVEN** a schema with `objectSlugField: "title"` and no object holding `bulk-item`
- **WHEN** a bulk save creates two objects with `title: "Bulk Item"`
- **THEN** the first object in batch order MUST get slug `bulk-item`
- **AND** the second MUST be saved without a slug
- **AND** one collision warning MUST name both uuids

#### Scenario: Bulk create against an existing holder
- **GIVEN** object A holds slug `bulk-item`
- **WHEN** a bulk save creates an object with `title: "Bulk Item"` in the same register and schema
- **THEN** the new object MUST be saved without a slug
- **AND** A MUST keep `bulk-item`

#### Scenario: Bulk update keeps a frozen slug
- **GIVEN** object A holds slug `bulk-item`
- **WHEN** a bulk save updates A with `title: "Renamed Item"`
- **THEN** A's `@self.slug` MUST still be `bulk-item`

### Requirement: A slug lookup that matches more than one object in a register and schema MUST be refused as ambiguous

A lookup by identifier within one register and schema MUST match the object's numeric
id, uuid, slug or uri. When it matches more than one live row
(`MagicMapper::findInRegisterSchemaTable()`) and no single row is matched by its id or
uuid, it MUST throw `MultipleObjectsReturnedException` and MUST NOT return either
object. Before throwing, it MUST log a warning naming the register, the schema,
the identifier and the uuids of the matching rows. The answer to such a lookup MUST be
HTTP 409 with a JSON body that says the identifier is ambiguous, on every route that
resolves an object identifier through `ObjectService::find()` or `findSilent()`
(show, update, patch, destroy, canDelete, lock, unlock, move, the presence routes,
downloadFiles, referencedBy, geoFeatures, referenceOptions and the other `{id}` routes
of `ObjectsController`, the relations route of `RelationsController` and the object
history route `…/{id}/audit-trails` of `AuditTrailController`), each keeping its other
error codes. The body MUST NOT list the matching uuids. The history route MUST look the
object up within the register and schema of the route, deleted objects included, so an
identifier of this register and schema never answers with another schema's history; only
when the register or schema itself no longer resolves does it fall back to the cross-table
lookup. The uses and used sub-routes MUST answer the same 409 instead of an empty list.
The files listing `…/{id}/files` MUST list the files of the object its read check resolved
within the register and schema, never of a cross-table lookup of the raw identifier; a
slug held by two objects answers 404 there, the answer that route gives every refused read
(file-actions REQ-OFOA-002), and it MUST NOT list either object's files.

#### Scenario: The history route is scoped to the register and schema
- **GIVEN** object A with slug `hello-world` in register 28 / schema 68 and an older object with slug `hello-world` in schema 63
- **WHEN** `GET /api/objects/28/68/hello-world/audit-trails` is requested by an administrator
- **THEN** the response MUST list A's history only
- **AND** `GET /api/objects/28/68/dubbel/audit-trails`, where two objects of 28/68 hold `dubbel`, MUST be HTTP 409 with `"error": "ambiguous-identifier"`

#### Scenario: The files and relations of a slug are those of this schema's object
- **GIVEN** object A with slug `hello-world` in register 28 / schema 68 holding one file, and objects D and E of 28/68 both holding slug `dubbel`
- **WHEN** `GET /api/objects/28/68/hello-world/files` is requested by a reader of A
- **THEN** the response MUST list A's file
- **AND** `GET /api/objects/28/68/dubbel/uses` and `…/dubbel/used` MUST be HTTP 409 with `"error": "ambiguous-identifier"`
- **AND** `GET /api/objects/28/68/dubbel/files` MUST be HTTP 404 and list no file

An exact match on the object's numeric `_id` or `_uuid` MUST win over a slug match: a
slug that equals another object's id (`2024`) or uuid does not make that object
unreachable. Only a canonical non-negative integer (digits only, no leading zero, such as `2024`)
is an object id; `007`, `7.0`, `1e3` and `7 ` are never read as an id, so they can only
match a slug, uuid or uri. Only when no single row is matched by its id or uuid,
because several rows hold the slug, is the identifier ambiguous. The lookup reads at
most 50 rows, rows matched by id or uuid first and the rest in id order, and a result cut
by that limit stays ambiguous.

#### Scenario: Pre-existing duplicate slug answers 409
- **GIVEN** objects A and B in the same register and schema both hold slug `hello-world` from before this change
- **WHEN** `GET /api/objects/{register}/{schema}/hello-world` is requested
- **THEN** the response MUST be HTTP 409 with a JSON body naming the identifier as ambiguous
- **AND** neither object MUST be returned
- **AND** one warning MUST be logged naming the register, the schema, `hello-world` and the uuids of A and B

#### Scenario: A numeric slug does not mask the object with that id
- **GIVEN** object A holds slug `2024` and object B has numeric id 2024
- **WHEN** `GET /api/objects/{register}/{schema}/2024` is requested
- **THEN** the response MUST be HTTP 200 with object B

#### Scenario: A uuid-shaped slug does not mask the object with that uuid
- **GIVEN** object A holds a slug equal to the uuid of object B
- **WHEN** `GET /api/objects/{register}/{schema}/<that uuid>` is requested
- **THEN** the response MUST be HTTP 200 with object B

#### Scenario: A zero-padded number is a slug, not an id
- **GIVEN** object A holds slug `007` and object B has numeric id 7
- **WHEN** `GET /api/objects/{register}/{schema}/007` is requested
- **THEN** the response MUST be HTTP 200 with object A
- **AND** `GET .../7` MUST return object B

#### Scenario: Several slug holders stay ambiguous, also beyond the row limit
- **GIVEN** 60 objects hold slug `hello-world` and none has that id or uuid
- **WHEN** the slug is requested on any route that resolves an identifier, such as `GET .../hello-world/files/download` or `POST .../hello-world/move`
- **THEN** the response MUST be HTTP 409 and MUST NOT name any uuid

#### Scenario: A unique slug still resolves
- **GIVEN** only object A holds slug `hello-world` in its register and schema
- **WHEN** `GET /api/objects/{register}/{schema}/hello-world` is requested
- **THEN** the response MUST be HTTP 200 with object A

### Requirement: `occ openregister:backfill-slugs` MUST fill missing slugs idempotently and MUST NOT silently change duplicates

OpenRegister MUST provide `occ openregister:backfill-slugs <register> <schema>`, where
each argument accepts an id, uuid or slug. The command MUST refuse, with a non-zero exit
code and a message, a schema without `objectSlugField`. It MUST give a slug to every
live object that has none, oldest object first (by `_created`, then `_id`), applying
the same candidate, uniqueness and suffix rules as a save. It MUST NOT change an object
that already holds a slug, also not after `objectSlugField` was switched: the command
has no option that rewrites set slugs (Robert Zondervan, 2026-10-23). It MUST report
every slug that more than one live object holds before the run, as a log warning and a
console line naming the slug and the uuids, and MUST leave those duplicates unchanged.
With `--dry-run` it MUST report what it would change without writing. The command MUST
be idempotent: a second run changes nothing. It writes the slug column directly, in
transactions of at most 500 rows, and each write applies only when the row still has no
slug (a row a save gave a slug meanwhile is skipped and logged). It does not bump the
object version, does not write a per-object audit-trail entry and does not dispatch
object events. Instead, every run that is not a dry run and changes at least one slug
MUST write one audit-trail entry (`slug.backfill.fill`, on the register and schema)
that lists the objects whose slug it set as uuid, old slug (null) and new slug. The
entry lists at most the first 1,000 changes and carries the total (`changesTotal`) and
`truncated`; the full list of a larger run MUST be written to the log. The entry is
hash-chained and cannot be redacted afterwards, so it MUST copy nothing but uuid, old
slug and new slug. A run that changes nothing, and a dry run, MUST NOT write an entry
(the console summary still reports it). When the entry cannot be inserted, the run logs
an error line with the changes instead, and the slugs already written stay.

#### Scenario: The backfill fills only empty slugs
- **GIVEN** a schema with `objectSlugField: "title"` holding object A with slug `a-title` and object B without a slug and `title: "B Title"`
- **WHEN** `occ openregister:backfill-slugs <register> <schema>` runs
- **THEN** B's slug MUST be `b-title`
- **AND** A's slug MUST still be `a-title`

#### Scenario: Second run changes nothing
- **GIVEN** the backfill has run once on a register and schema
- **WHEN** it runs again
- **THEN** it MUST report zero assigned objects

#### Scenario: Existing duplicates are logged, not changed
- **GIVEN** objects A and B in the same register and schema both hold slug `hello-world`
- **WHEN** the backfill runs
- **THEN** both MUST still hold `hello-world`
- **AND** the output and the log MUST name `hello-world` with the uuids of A and B

#### Scenario: A switched slug field leaves set slugs alone
- **GIVEN** a schema whose `objectSlugField` was switched from `kenmerk` to `title`
- **AND** object A with `kenmerk: "K-1"`, `title: "Hello World"` and slug `k-1`, and object B with `title: "Second"` and no slug
- **WHEN** `occ openregister:backfill-slugs <register> <schema>` runs
- **THEN** A's slug MUST still be `k-1`
- **AND** B's slug MUST be `second`
- **AND** the command MUST NOT offer an option that rewrites A's slug

#### Scenario: A run leaves one audit-trail entry
- **GIVEN** a run that gives objects A and B their first slug
- **WHEN** the run finishes
- **THEN** exactly one audit-trail entry MUST exist for it, listing A and B with old and new slug
- **AND** no object's version MUST have changed
- **AND** a `--dry-run` MUST write no entry

#### Scenario: A large run keeps the entry bounded
- **GIVEN** a run that assigns 2,500 slugs
- **WHEN** the run finishes
- **THEN** the audit-trail entry MUST list 1,000 changes, carry `changesTotal` 2500 and `truncated` true
- **AND** the full list MUST be in the log, in parts of at most 1,000

#### Scenario: A run that changes nothing writes no audit-trail entry
- **GIVEN** a run in which every object without a slug wants a slug that is taken
- **WHEN** the run finishes
- **THEN** no audit-trail entry MUST be written

#### Scenario: Dry run writes nothing
- **GIVEN** an object without a slug in a schema with `objectSlugField`
- **WHEN** the backfill runs with `--dry-run`
- **THEN** the output MUST list the slug it would assign
- **AND** the object MUST still have no slug

#### Scenario: A schema without objectSlugField is refused
- **GIVEN** a schema without `objectSlugField`
- **WHEN** the backfill runs for it
- **THEN** the command MUST exit non-zero with a message naming the missing configuration
- **AND** no object MUST be changed

### Requirement: The schema editor MUST let an administrator configure the slug field and the suffix

The schema editor's configuration tab MUST offer an "Object slug field" dropdown whose
options are the schema's properties, next to the existing object name, description,
image and summary field dropdowns. It MUST also offer a switch for `objectSlugSuffix`
that is disabled while no slug field is chosen. Both values MUST round-trip through a
save of the schema. Clearing the dropdown MUST store `objectSlugField` as `null`. Every
new user-visible string MUST be wrapped for translation and present in the translation
catalogue of the app that renders it.

#### Scenario: Choosing a slug field persists it
- **GIVEN** a schema with properties `title` and `kenmerk`
- **WHEN** an administrator picks `kenmerk` in "Object slug field" and saves the schema
- **THEN** the stored configuration MUST contain `objectSlugField: "kenmerk"`
- **AND** reopening the editor MUST show `kenmerk` selected

#### Scenario: The suffix switch needs a slug field
- **GIVEN** a schema without `objectSlugField`
- **WHEN** the configuration tab is opened
- **THEN** the `objectSlugSuffix` switch MUST be disabled

### Requirement: The object-slug rules MUST be backwards compatible

The new rules are opt-in through `objectSlugField`, and the change MUST preserve the
following behaviour. Each item MUST have its own automated test.

- BC1: a schema without `objectSlugField` behaves as before. No slug is generated, and
  no uniqueness check, freeze or slug warning applies. A client-supplied slug is stored
  as before.
- BC2: existing slugs are left as they are, including duplicates and `-<timestamp>`
  variants. There is no migration, no unique index on the slug column, and no write on
  upgrade.
- BC3: lookups by uuid and by numeric id behave as before.
- BC4: the new configuration keys are optional. A schema or export without them behaves
  as before.
- BC5: a client-supplied `@self.slug` or `slug` stays accepted.
- BC6: bulk and single save give the same result for the same input.

#### Scenario: BC1 — a schema without objectSlugField stays slugless
- **GIVEN** a schema without `objectSlugField`
- **WHEN** two objects with `title: "Hello World"` are created and one is renamed
- **THEN** both MUST have `@self.slug` `null`
- **AND** no slug collision or freeze warning MUST be logged

#### Scenario: BC1 — a client slug on a schema without objectSlugField is stored as before
- **GIVEN** a schema without `objectSlugField` and object A created with `@self.slug: "first"`
- **WHEN** A is updated with `@self.slug: "second"`
- **THEN** A's `@self.slug` MUST be `second`, as before this change

#### Scenario: BC2 — existing duplicate and timestamp slugs survive a save
- **GIVEN** objects A and B both holding slug `hello-world` and object C holding `hello-world-renamed-1700000000`, all saved before this change
- **WHEN** each of A, B and C is saved again without other changes
- **THEN** A and B MUST still hold `hello-world` and C MUST still hold `hello-world-renamed-1700000000`

#### Scenario: BC3 — uuid and numeric id lookups are unchanged
- **GIVEN** objects A and B both holding slug `hello-world`
- **WHEN** A is requested by its uuid and B by its numeric id
- **THEN** each request MUST return that object with HTTP 200

#### Scenario: BC4 — a schema export without the new keys imports unchanged
- **GIVEN** a schema export whose configuration has `objectNameField` but neither `objectSlugField` nor `objectSlugSuffix`
- **WHEN** it is imported
- **THEN** the imported configuration MUST equal the exported one
- **AND** objects saved in it MUST behave as before this change

#### Scenario: BC5 — integrations can still send a slug
- **GIVEN** a schema with `objectSlugField: "title"` and no object holding `integration-slug`
- **WHEN** an integration creates an object with top-level `slug: "integration-slug"`
- **THEN** its `@self.slug` MUST be `integration-slug`

#### Scenario: BC6 — bulk and single give the same slugs
- **GIVEN** two identical, empty registers with the same schema setting `objectSlugField: "title"`
- **WHEN** the same three objects, two of them titled `"Same"`, are saved one by one in the first and in one bulk save in the second
- **THEN** the resulting slug of each object MUST be the same in both registers

## MODIFIED Requirements

### Requirement: The object read endpoint MUST resolve slugs and return a 404 envelope on miss

`ObjectsController::show()` MUST accept a register and schema as slug or numeric
ID, resolve them to entities via `resolveRegisterSchemaIds()`, and return the
single object honoring the request's extend and field-filter parameters. If the
register or schema cannot be resolved, the response MUST be HTTP 404 with a
`message` body. If the object identifier matches more than one object in the
resolved register and schema (an ambiguous slug), the response MUST be HTTP 409
with a JSON body naming the identifier as ambiguous, and neither object MUST be
returned.

#### Scenario: Show resolves slugs and returns the object
- **GIVEN** an existing object addressed by `{register}/{schema}/{id}` using slugs
- **WHEN** `show()` is called
- **THEN** the register and schema slugs MUST be resolved to entities before the read
- **AND** the response MUST be the rendered object honoring any `_extend` / field-filter parameters

#### Scenario: Show on unknown register/schema returns 404
- **GIVEN** a request whose register or schema does not exist
- **WHEN** `resolveRegisterSchemaIds()` throws `RegisterNotFoundException` or `SchemaNotFoundException`
- **THEN** the response MUST be HTTP 404 with a `message` body

#### Scenario: Show on an ambiguous object slug returns 409
- **GIVEN** two objects in the resolved register and schema that both hold the requested slug
- **WHEN** `show()` is called with that slug as `{id}`
- **THEN** the response MUST be HTTP 409 with a JSON body naming the identifier as ambiguous
- **AND** the response MUST NOT contain either object or their uuids
