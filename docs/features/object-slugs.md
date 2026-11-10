# Object Slugs

Stable, human-readable identifiers for objects: a slug is derived once from a configured field, is unique within its register and schema, and never changes after it has been set, so a link built on it keeps working.

## Specs

- [object-lifecycle](../../openspec/specs/object-lifecycle/spec.md) — the slug requirements live in the object lifecycle spec (change `object-slugs-configurable-and-unique`, archived 2026-11-05)

## Standards

- NL API Design Rules — stable, readable resource identifiers alongside the UUID
- Common Ground — objects stay addressable by the same reference from any consuming app

## Overview

A schema switches slugs on with two configuration keys:

- `objectSlugField` — the property the slug is derived from, or the reserved value `@schema.name` for `<schema slug>-<object name>` (the name comes from `objectNameField`). Both keys survive schema save, configuration import and the schema editor's Configuration tab.
- `objectSlugSuffix` — off by default, so slugs are reproducible. On, a slug that is already taken gets a UTC date-time suffix instead of being dropped.

## Features

- **Unique per register and schema.** A second object whose source value slugifies to a taken slug is saved without a slug and a warning names both objects; with the suffix on it gets `<slug>-<YmdHis>`. The same slug in another schema is not a collision.
- **Frozen once set.** Renaming the source field, updating other fields or sending a client slug never rewrites an existing slug. An object without a slug gets one at its next save, also when the holder of that slug has since been deleted.
- **Bulk and single save follow the same rules**, including client-supplied slugs that differ only in case.
- **Lookup refuses ambiguity.** A slug that matches more than one object (a pre-existing duplicate) answers HTTP 409 `ambiguous-identifier` on the object, history, files and relation routes instead of silently returning one of them; uuid and numeric id lookups are unchanged.
- **Backfill.** `occ openregister:backfill-slugs <register> <schema> [--dry-run]` fills the missing slugs of existing objects, oldest first, never rewrites a set slug, lists existing duplicates without changing them and leaves one audit-trail entry per run that changed something.
- **Backwards compatible.** A schema without `objectSlugField` stays slugless, existing duplicate and timestamp slugs survive a save, and a schema export without the new keys imports unchanged.
