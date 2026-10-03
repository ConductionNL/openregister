# Design: classification clearance on an object

## D-1 · The order belongs to the schema, not to OpenRegister

dossiq hardcodes eight ZGW levels. `CaseTypeAuthorizationService` hardcodes the
same eight. Two copies of one list, and OpenRegister does not otherwise know
what a `vertrouwelijkheidaanduiding` is.

**Decided:** a schema declares its own ordered list, lowest first, in a
`x-openregister-classification` block naming the property that carries the
label and the levels in order. ZGW's eight become a configuration dossiq
installs, on the same footing as a BIO level, a security marking, or a
three-step internal scale.

The alternative, shipping the ZGW list as a platform constant, was rejected for
the reason `ArchiveActionDateCalculator`'s header already gives about
`brondatumArchiefprocedure`: OpenRegister implements a mechanic once and never
learns what a zaak is.

## D-2 · Clearance is a mapping, not a property of the user

A clearance could live on the user record, on a group, or in a map from group
to level. dossiq uses the map, in an app-config string of `group:level` pairs.

**Decided:** keep the map and move it onto the schema's classification block,
as a list of `{ group, level }` entries. Highest wins. A principal no entry
names takes the declared `default`.

Reasons. A per-user property needs a provisioning path OpenRegister does not
own. A per-group property needs a group attribute store Nextcloud does not
have. The map is data the register already knows how to hold, and it is
readable by the same admin who reads the `authorization` block beside it.

Administrators take the top level, as they do in dossiq today. That is the
existing `admin` bypass and is not new.

## D-3 · Fail direction

dossiq fails closed on the object and open on the principal: an unknown label
is the most restrictive level, an unresolved clearance is the baseline.

**Decided: keep both, and say why they differ.** They are not inconsistent.
Both push toward refusal. An unlabelled object becomes maximally protected. An
unmapped user becomes minimally cleared. The pair is the safe corner in each
direction, not a compromise.

The declared `default` clearance can be set above the lowest level, which is
how a tenant says "everyone here is cleared to `intern`". That is a deliberate
widening and it is the tenant's to make, out loud, in the schema.

## D-4 · The comparison runs in SQL

This is the half an app cannot build.

dossiq's `filterDossierForUser()` removes rows after the query returns. The
page size is then wrong, the total is wrong, and a caller paging through a
classified register sees gaps it cannot explain.

**Decided:** the ordinal comparison compiles into the list query in
`MagicRbacHandler`, beside the `$contains` and deny predicates already there.
Levels are compared by their index in the declared list, so the predicate is an
`IN` over the labels at or below the caller's clearance. That works on both
PostgreSQL and MySQL without an ordinal column and without a migration.

`PermissionHandler` makes the same comparison on the find path, from the same
declared list. The layer-agreement test from
`an-app-declares-object-access-rather-than-guarding-it` covers this pair too.

## D-5 · A ceiling names a scope, not a feature

dossiq's `canPublish()` hardcodes "at or above `vertrouwelijk`, no public
share". Written that way it only knows about publishing, and the next surface
that exposes an object has to remember it.

**Decided:** a schema declares `ceilings` as a list of `{ scope, atOrAbove }`.
The scope is a principal from the existing vocabulary, `public` first among
them. An object at or above that level is never admitted to that scope,
whatever a grant or a schema rule says. The rule then applies to a share link,
a federated share, an export and an anonymous read, without any of those being
named.

A ceiling beats a grant on purpose. It is the one place in this design where a
declaration refuses rather than narrows.

## D-6 · No downgrade, and where the floor comes from

dossiq compares a requested level against the informatieobjecttype's default.
That is a second hop, and OpenRegister's declaration language has one hop.

**Decided:** the floor is read from a declared property, either on the object's
own schema or off a single reference, using the same `sourceRelation` shape
`ArchiveActionDateCalculator` already uses for a base date. One hop covers
dossiq's case, because the type reference sits on the informatieobject.

A write that names a level below the floor is refused at save, naming both
levels. A write that raises it is allowed, because raising is always the safe
direction.

## D-7 · What happens to CaseTypeAuthorizationService

It holds the ZGW ordinals, the `maxVertrouwelijkheidaanduiding` mapping and a
matrix extraction, and nothing calls it.

**Decided:** it becomes the ZGW adapter, converting an Autorisaties API record
into a `x-openregister-classification` block plus an `authorization` block, and
it gains the caller that makes it real. If the adapter is not needed, it is
deleted. It does not stay as it is. A class of authorization logic with no
caller reads as coverage and is not.
