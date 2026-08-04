# ADR-006: "Published" is an RBAC scope, not a data field

**Status**: accepted (documents the decision as implemented)

**Date**: 2026-07-07

## Context

Consumers such as opencatalogi (DCAT-AP publishing) need a notion of objects
being "published" — visible to anonymous/public readers — versus internal.
The intuitive design is a boolean data property (`published: true`) on the
object that a user flips. OpenRegister deliberately does not model it that way,
and consumers repeatedly re-derive the actual rule.

Publication is modelled as an **RBAC/authorization concern at the schema level**
(`lib/Db/Schema.php` carries an `authorization` JSON block —
`getAuthorization()`/`setAuthorization()`, typed `json`, at
`lib/Db/Schema.php:84,262,467`), not as a data field on `ObjectEntity`. Whether
an object is readable by the `public`/anonymous principal is decided by the
schema's authorization configuration and the RBAC evaluation on read, not by a
`@self.published` value the caller can set directly.

## Decision

**Publication state is expressed through schema-level RBAC scopes, not a
data property. There is no authoritative `published` boolean on the object that
consumers flip to change visibility.**

### Numbered rules

#### Rule 1 — Visibility is decided by RBAC on read, not a data flag

Whether an object is returned to a given principal (including the anonymous
public principal) is determined by the schema `authorization` block evaluated
during `find`/`search`. A data property named `published` (if present for
display) is descriptive metadata, not the access-control decision.

#### Rule 2 — To publish, grant a read scope, do not set a field

Making objects of a schema publicly readable means configuring the schema's
authorization to grant read to the public principal — an RBAC change — not
writing a boolean onto each object.

#### Rule 3 — Consumers must not treat `published` as a security boundary

opencatalogi and other DCAT-AP consumers MUST rely on OR's RBAC evaluation for
public exposure. Filtering client-side on a `published` field is not a security
control; the server decides visibility.

#### Rule 4 — A share LINK is a capability, and is not publication

Added 2026-08-02 with the object-level sharing capability.

An owner MAY create a tokenised link to a single object. That does not violate
Rules 1–3, and the distinction is worth stating because it looks like it should.

What Rule 3 actually guards against is a DATA FIELD being treated as a boundary:
a `published` property is written by whoever can write the object, is visible to
whoever can read it, and is enforced by nobody. A share link is none of those
things. It is a bearer capability issued by Nextcloud core, and core is what
validates it:

- it is **revocable** — the record is deleted and the next request is refused;
- it is **expiring**, and optionally **passworded**;
- it is **attributable** — the share records who created it;
- it is **not a property of the object**. Creating one changes nothing about the
  object, and the RBAC verdict for every logged-in principal is unchanged.

That last point is the operative one, and it is enforced rather than asserted:
`ObjectShareLinkIntegrationTest::testALinkDoesNotMakeTheObjectListableForOthers`
fails if creating a link ever widens the verdict for other users.

So the rules compose. Publication is still a schema-level RBAC change and still
must not be a data flag. A link is a per-object, revocable grant of access to
whoever holds the token — a different act, decided on a public endpoint from the
token rather than in the RBAC filter, because an anonymous caller presents no
principal for the filter to resolve.

**What this does NOT license.** A link is not a way to publish a schema one
object at a time as a matter of policy. If a whole class of objects should be
public, that is Rule 2's RBAC change; reaching for links instead trades a
server-enforced scope for a pile of bearer tokens nobody can audit as a set.

## Consequences

- (+) A single, server-enforced visibility model; no gap between a data flag and
  the actual permission.
- (+) Publishing a whole schema's worth of objects is one RBAC change, not a
  per-object write.
- (−) "Publish this one object" is expressed differently than authors expect;
  needs to be surfaced clearly in publishing UIs and docs.
- Cross-reference: this is the OR-side companion to the fleet memory note
  "publish is RBAC not @self.published" and underpins the opencatalogi
  publishing model (ADR-022 apps-consume-OR-abstractions).
