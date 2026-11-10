# Proposal: or-silent-field-loss

## Summary

Two silences that turn out to be one story: a slug that resolves to whichever
same-slug schema the storage engine hands back first, and a property the schema
does not declare being discarded with no signal at all.

## Why

Measured on the live instance, 2026-08-02.

**The duplicate.** Schemas 5012 "AgentFlow" (`application=''`, v0.0.1) and 5020
"Agent flow" (`application='hermiq'`, v0.1.2) both carry slug `agentflow` in one
organisation. `SchemaMapper::find()` — the resolver every scoped lookup falls back
to — had no `ORDER BY` and no row cap, so which one won was the storage engine's
choice. Run the query as the code ran it and Postgres returns **5012 first**: the
stale, unowned copy, which is missing `description`, `notes`, `owner` and
`triggerRegister`. The 64 live objects are all in **5020**.

So a naive fix is worse than none: `ORDER BY id ASC` — the pattern
`RegisterMapper::find()` already uses — would also pick 5012.

**The drop.** `prepareObjectDataForTable()` is a whitelist by omission: it walks
the schema's declared properties and copies those out of the payload. Everything
else is never read, and there is no `object` blob column, so it is gone. No error,
no warning, no trace. On the live agentflow table there is no `$bindings` and no
`$comment` column, while every `hydra/flows/*.flow.json` carries both — they have
been evaporating on every save.

And the two connect: a save that resolved the schema to 5012 would prepare its
row against 5012's property list, which has no `description`, and drop the field
on the way to 5020's table. Field loss caused by ambiguous resolution, invisible
at both ends.

## What Changes

- **`SchemaMapper::find()`** orders candidates — owned before unattributed, then
  lowest id — reads two rows so ambiguity is *knowable*, and warns naming every
  candidate when there is more than one.
- **`DatabaseConstraintException`** recognises the post-migration index names.
  `Version1Date20260723000000` renamed them on 2026-07-23 and this parser was not
  updated, so the friendly slug-collision message had been silently dead on every
  migrated instance ever since.
- **`MagicMapper::prepareObjectDataForTable()`** warns, naming every discarded
  property and the schema.
- **`ImportService`** adds a `warnings` entry per row, because it filters
  undeclared keys *before* the save where the mapper's warning cannot see them.

## What this deliberately does NOT do

**It does not add a uniqueness constraint on schema slugs.** Cross-application
slug sharing is a deliberate, documented design:
`Version1Date20260723000000` widened the key from `(organisation, slug)` to
`(organisation, application, slug)` precisely because the narrow key made a
generic slug shipped by app B (`conversation`, `order`, `task`) a hard collision
with app A's, so the importer silently bound B to — or overwrote — A's schema.
Re-narrowing it would put that back. What did not land with that migration is the
determinism its own docblock promised; that is what this supplies.

**It does not reject an undeclared property.** The DB write boundary is far too
late for a clean 400 (the entity is built, folders may exist, cascades have run),
and rejecting would break every caller that harmlessly posts an extra key. The
requirement is visibility. Somebody has to be able to tell "this schema needs a
property added" from "this field evaporated"; today those look identical.

**It does not repair the live duplicate.** Two schemas exist on a shared instance
and `occ openregister:schemas:dedup` is the operator tool for that. The code
change makes the resolution correct and the ambiguity loud in the meantime.
