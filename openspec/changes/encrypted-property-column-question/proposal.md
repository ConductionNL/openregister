---
kind: docs
---

## Why

The `field-level-encryption` spec and the code disagree on where an encrypted property lives.

The requirement "Encrypted fields are excluded from search and facets" says a property flagged `x-openregister-encrypted: true` SHALL NOT be given a dedicated, independently-queryable magic-table column.

The code gives it one. `MagicSchemaColumns::storedColumn()` makes a nullable, unindexed `text` column named after the property, and that column is the only place the value is stored. It holds the `openregister:enc:v1:` envelope, whatever type the schema declares. `MagicSearchHandler` refuses filters on the property with HTTP 400, the full-text scan skips it, and `FacetableFieldDiscovery` leaves it out of the facets.

The rest of the requirement holds. Only the first clause does not match.

## What Changes

Nothing yet. This change records the disagreement so that it can be decided. It does not change the spec text or the behaviour. The delta and the tasks follow once the question below is answered.

## Open questions (Ruben)

1. Which side is right, the spec or the code?
   - **Option A, amend the spec to the code.** The property keeps its own `text` column, which holds only the envelope, has no index and refuses filtering, faceting and search. The spec then says what the column is, not that there is none. No migration.
   - **Option B, change the code to the spec.** Encrypted properties leave their own columns and move to a shared store on the row. Magic tables have no such store today, so this needs a new column, a migration of every table with an encrypted property, and new read and write paths, including `openregister:encrypt-field`.

   Option A is the smaller step. The clause most likely means "no column anyone can query", and the search handler already enforces that. The one thing Option A gives up is hiding which properties a table encrypts from someone who reads the database schema.
2. Should a sort on an encrypted property be refused too? The spec does not mention sorting. In the code `MagicSearchHandler` only drops the lower-casing and date handling for such a property, so a sort still runs and orders on the raw envelope, which means nothing to the caller. This holds under both options of question 1.

## Impact

- `openspec/specs/field-level-encryption/spec.md`, requirement "Encrypted fields are excluded from search and facets", once decided.
- `lib/Db/MagicMapper/MagicSchemaColumns.php`, `lib/Db/MagicMapper.php`, `lib/Db/MagicMapper/MagicSearchHandler.php` and `lib/Command/EncryptFieldCommand.php`, only under Option B.
