# An organisation is addressable as an object

## Why

Several apps grew their own `organization` SCHEMA, and measuring one showed why.
`publication.organization` and `catalog.organization` are declared as
`{"type": "string", "format": "uuid", "$ref": "organization"}`. A `$ref`
resolves against a SCHEMA, and OpenRegister's Organisation is an ENTITY with no
object projection, so there was nothing for that reference to point at. Each app
declared its own copy instead.

A schema slug is global per organisation — `SchemaMapper::find()` matches
`LOWER(slug)` across every app and returns the first row it reaches — so those
copies collide. `organization` is currently claimed by both opencatalogi and
stackiq.

Adding the identity columns to Organisation (change
`consolidate-organisation-on-or`) made reuse possible at the entity level, and
`openregister:organisations:adopt` moves the rows. Neither gives a leaf schema
something to reference. This does.

## What changes

An `nc-organisation` virtual schema on the always-available `directory`
register, served read-only by an `OrganisationObjectSourceProvider`.

This is not a new mechanism. OpenRegister already projects `nc-user` and
`nc-group` exactly this way, and `nc-group` is even mapped to
`schema:Organization`. The provider follows `GroupObjectSourceProvider`
line for line: the same read-only contract, the same acting-user scoping, the
same "absent and denied are indistinguishable" rule.

The schema is `nc-`-prefixed for the reason the map already states for the
app-gated rows: it must not collide with the leaf-app `organization` schemas it
exists to replace, which have to keep working until each app has migrated off
them.

## Three deliberate limits

**Read-only.** The authoritative record is the Organisation row. A write path
here would be a second way to mutate a tenant, reachable through the object API
and bypassing `OrganisationService`'s lifecycle.

**The identity facet only.** Quota, users, groups and authorization are tenancy
administration. This schema exists so another record can REFERENCE an
organisation, not so anyone can configure one through the object API.

**Scoped, and not an enumeration oracle.** An organisation IS the tenant
boundary. An admin sees all of them; anyone else sees only the ones they belong
to; absent and denied both return null. Anonymous callers see nothing.

## A defect this found

`project()` was first written with `method_exists($organisation, 'getName')`
before calling the getter. Organisation's accessors are magic
(`Entity::__call`), so `method_exists` is FALSE for every one of them and the
projection would have shipped carrying nothing but an id — a schema that
resolves, returns objects, and is empty. The tests caught it; reading the code
did not.
