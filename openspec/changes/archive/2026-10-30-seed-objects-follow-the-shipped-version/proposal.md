# Seed objects follow the shipped version

## What was wrong

An app ships seed objects in its register configuration, each with a stable
`@self.slug` and a `@self.version`. The importer updates a stored seed when the
shipped version is higher. On the Zuiddrecht demo instance (14 Oct) dossiq's Woo
case type, shipped at 1.4.7 with a handling team and a flow, never arrived on an
upgraded instance, while fresh installs were fine.

Three things in OpenRegister let it stay lost:

1. **The refusal was swallowed and the import was remembered as done.** dossiq's
   save listener refused the update (it judged the case type by a flow that was
   not written yet). The per-object catch logged one warning, and the import
   still stored its content hash. Every later upgrade fast-skipped on that hash,
   so the seed was never tried again. Only a forced re-import retried it.
2. **Order inside one import was fatal.** A seed listed before a row it depends
   on failed once and was never retried in the same run.
3. **An update dropped the shipped version.** The save bumped the stored patch
   (`0.0.4`) instead of taking `1.4.7`, so the next import read the seed as
   outdated again and re-applied it.

A fourth defect sat on the same path: when `searchObjects` answered with arrays,
`jsonSerialize()` was called on an array, and every existing seed failed.

## What changes

- A listed object that fails is retried once, after every other listed object
  has been written. Only the second failure is recorded.
- The import result names every object it could not write in
  `failed.objects`, with the slug, schema, reason and whether a listener
  refused it.
- When a listener refused a listed object, the content hash is not stored, so
  the next run imports again. A failure the file causes (schema validation) does
  not withhold the hash: the same content would fail the same way on every
  repair, and dossiq alone ships about forty such rows today.
- An update that carries a usable `@self.version` higher than the stored one
  takes that version. Any other save bumps the patch as before.

## What counts as a seed

An object listed in a configuration (`components.objects` or top-level
`objects`) with a `@self.slug`. It is found again by register, schema and slug.
Objects the configuration does not list are never read or written by the
import, so instance data stays untouched. Decision of 14 Oct: the shipped file
wins for configuration, so a higher shipped version replaces the stored values.
