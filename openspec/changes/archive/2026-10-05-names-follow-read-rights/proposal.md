---
kind: code
---

# Proposal: names-follow-read-rights

## Why

`POST /api/names` (and `GET /api/names`) answered an empty map for every non-admin
whose active organisation did not own the objects, even when that user could read
those objects. Seen on 2026-10-02 in the Tilburg Woo dashboard: `tilburg-demo`
(groups `ambtenaar` and `gebruik-beheerder`) reads the stackiq `module` objects
through `GET /api/objects/26/961/{id}`, because schema `module` grants `read` to
`gebruik-beheerder`, but the names lookup returned nothing, so the "Applicatie" and
"Voorgesteld door" columns showed raw UUIDs. Admin only got the names because
admin's active organisation happened to own the rows.

The cause: the name cache had a rule of its own. It decided visibility by
organisation only (the caller's active organisation plus its parents), so every
schema or register read grant across organisations, every public or conditional
rule, every owner and object grant was invisible to it. Two definitions of "may
read" had drifted apart.

## What changes

One rule, written once:

> A caller gets the name of every object they may read, and nothing for an object
> they may not read.

"May read" is no longer decided by the name cache. It is asked of the object read
path itself: `MagicMapper::filterReadableUuids()` runs the same access-control
filter as `GET /api/objects/{register}/{schema}/{id}`
(`MagicSearchHandler::applyAccessControlToQuery`, RBAC and multitenancy on). So a
names lookup can never disclose more, or less, than reading the object would.

- Every cached name now records its source: the object's table
  (`<registerId>:<schemaId>`) or `organisation`. A cached entry without a source
  (anything written before this change) is a miss and is resolved again, never served.
- An organisation's name keeps the organisation scope it had: an organisation is
  not an object and has no read path to ask.
- The answer per caller is remembered for the rest of the process, keyed by user and
  active organisation, and forgotten for everyone when the object changes.
- Every caller of `CacheHandler::getMultipleObjectNames()` (exports, facets, the
  `_names` of an object read, hydration) gets the same rule.

Closing the leak in the other direction is part of the same rule: an object in the
caller's own organisation whose schema denies the caller read access no longer
discloses its name.

## Also in this change

**A userless read inside runAsSystem is a system read.** dossiq's portal Woo intake
(`POST /apps/dossiq/api/portal/woo-verzoek`, called server-side with no user) writes the
case inside `runAsSystem()`. The case's calculations resolve `caseType` and `statusType`
through ReferenceResolver with RBAC on, and MagicRbacHandler and
MagicOrganizationHandler only trusted a userless caller on the command line, so every
reference read clamped to nothing and the case got no deadline and no status label
(nextcloud.log: "Reference resolution failed for schema "caseType": ... not found in any
magic table"). PermissionHandler already trusted the scope; the magic-table filters now
agree. A logged-in user, a forced-anonymous evaluation, and a userless web read outside
the scope are filtered exactly as before.

**Related schemas are a catalog read.** `GET /api/schemas/{id}/related` answered "Schema
not found" for a non-admin outside the schema's organisation because
`SchemaMapper::getRelated()` looked the schema up with multitenancy on. Both its lookups
now bypass multitenancy, like the controller's outgoing half and `GET /api/schemas/{id}`.
