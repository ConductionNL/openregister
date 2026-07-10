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
