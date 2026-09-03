# Tasks

## 1. The projection

- [x] 1.1 `OrganisationObjectSourceProvider`, read-only, following
      `GroupObjectSourceProvider`.
- [x] 1.2 An `nc-organisation` row in `NcEntitySemanticMap` on the always
      available `directory` register.
- [x] 1.3 Property definitions in `SeedDirectoryVirtualSchemas`, kept in step
      with the provider's projected set — a property declared and not projected
      reads as permanently empty, and one projected and not declared is
      discarded by the store without a word.
- [x] 1.4 Register the provider in DI and in the provider list.

## 2. The limits

- [x] 2.1 Read-only: no write path through the object API.
- [x] 2.2 Identity facet only; no quota, users, groups or authorization.
- [x] 2.3 Scoped to the acting user, with absent and denied indistinguishable.
- [x] 2.4 A merged-away organisation is not offered: it owns nothing, and
      listing it invites a reference to a record that is not a usable target.
      `find()` still resolves one THROUGH the merge, so a reference stored
      before a merge keeps working.

## 3. Tests

- [x] 3.1 Ten unit tests covering the projected set, the omissions, the merge
      exclusion, search, and the anonymous case.

## 4. Verified live

- [x] 4.1 `GET /api/objects/{directory}/{nc-organisation}` returns the
      organisation with its identity facet.
- [x] 4.2 The same call unauthenticated returns `total: 0` — not an error, and
      not a row.
- [x] 4.3 `find` by uuid returns the organisation.
- [x] 4.4 Seeding on a clean row creates the schema WITH its properties and
      links it to the directory register.

⚠️ `ensureSchema()` reuses an existing schema and never updates it, by design.
A property added to `SCHEMA_PROPERTIES` therefore does NOT reach an instance
that already seeded that schema. Verified by deleting the row and re-running the
repair step. Any future property change needs its own migration.

## 5. What this unblocks, and does not do

- [ ] 5.1 Repoint opencatalogi's `publication.organization` and
      `catalog.organization` at `nc-organisation`, then retire its own
      `organization` schema.
- [ ] 5.2 The same for stackiq, which additionally needs a home for the nine
      properties Organisation has no column for (`xml`, `contactsUid`,
      `contactpersonen`, `deelnames`, `participants`, `samenwerkingtype`,
      `registeredBy`, `publicationDate`/`depublicationDate`).
