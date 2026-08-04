## ADDED Requirements

### Requirement: A slug that matches several schemas resolves deterministically (REQ-SL-001)

`SchemaMapper::find()` SHALL order its candidate rows before taking one, and
SHALL cap the read so the choice is explicit rather than left to the storage
engine. The order SHALL be:

1. an exact primary-key match, when the identifier is numeric (existing);
2. a row whose `application` is set, ahead of a row whose `application` is empty
   or null;
3. then the lowest `id`.

Rule 2 exists because the unique key is `(organisation, application, slug)`: the
key expresses OWNERSHIP, so a leftover row that no app claims SHALL NOT shadow an
app's real schema. Rule 3 matches `RegisterMapper::find()` and the
`openregister:schemas:dedup` keep-the-lowest rule.

Cross-application slug sharing SHALL remain permitted. Migration
`Version1Date20260723000000` widened the key deliberately so two apps can each own
a schema with a generic slug; reverting that would restore the collision it fixed.

When the identifier matches more than one schema, `SchemaMapper` SHALL log a
warning naming every candidate and the one it resolved to.

#### Scenario: An owned schema beats an unattributed one

- **GIVEN** two schemas share a slug, one with `application` set and one without
- **WHEN** the slug is resolved
- **THEN** the one with `application` set is returned

#### Scenario: An ambiguous identifier is reported

- **GIVEN** a slug matching more than one schema
- **WHEN** it is resolved
- **THEN** a warning names every candidate and the resolved row

@e2e exclude data-layer resolution — proven against the live duplicate
(schemas 5012 `application=''` and 5020 `application='hermiq'`, both slug
`agentflow`): the unordered query returns 5012 first, and so would a naive
`ORDER BY id ASC`; the new tie-break returns 5020, which is the schema the 64
live objects are in and the only one of the two that declares `description`

### Requirement: The slug-collision message survives the index rename (REQ-SL-002)

`DatabaseConstraintException` SHALL recognise both the pre-migration index names
(`schemas_organisation_slug_unique`, `registers_organisation_slug_unique`) and
the post-migration ones (`schemas_org_app_slug_unique`,
`registers_org_app_slug_unique`).

#### Scenario: The current index name produces the specific message

- **GIVEN** a duplicate-key error naming `schemas_org_app_slug_unique`
- **WHEN** it is translated
- **THEN** the message says a schema with this slug already exists

#### Scenario: An unrelated unique index keeps the generic message

- **GIVEN** a duplicate-key error naming some other unique index
- **WHEN** it is translated
- **THEN** the generic message is returned

@e2e exclude pure message mapping — covered by DatabaseConstraintExceptionSlugTest

### Requirement: A property the schema does not declare is never dropped silently (REQ-SL-003)

`MagicMapper::prepareObjectDataForTable()` copies only the schema's declared
properties into the row, and there is no JSON blob column to fall back on, so an
undeclared property is discarded permanently. It SHALL log a warning naming every
discarded property and the schema, so the loss is visible.

`ImportService` filters undeclared keys BEFORE the save, so that warning can never
see them; the import summary SHALL therefore carry a `warnings` entry naming the
row and the dropped properties.

Neither SHALL reject the write. The DB write boundary is far too late for a clean
400 — the entity is built, folders may exist and cascades have run — and rejecting
would break every caller that harmlessly posts an extra key. The requirement is
VISIBILITY, not refusal.

Envelope and metadata keys (`@`-prefixed, `_`-prefixed, `id`, `uuid`) SHALL NOT be
reported: they are not user data the schema was meant to declare, and reporting
them would fire on every save.

#### Scenario: An undeclared property is reported

- **GIVEN** a payload carrying `$bindings`, which the schema does not declare
- **WHEN** the object is saved
- **THEN** the property is still not stored, and a warning names it

#### Scenario: A fully declared payload reports nothing

- **GIVEN** a payload whose every key the schema declares
- **WHEN** the object is saved
- **THEN** no drop warning is logged

@e2e exclude data-layer write boundary — covered by MagicMapperTest with a
positive control; verified against live data (the agentflow table has no
`$bindings` / `$comment` column while every hydra flow document carries both)
