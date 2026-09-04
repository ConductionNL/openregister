# The organisation projection is writable

## Why

`nc-organisation` exists to retire the leaf-app `organization` schemas. A schema
slug is global per organisation, so opencatalogi, stackiq and others each
declaring their own meant `SchemaMapper::find()` returned whichever row it
reached first.

opencatalogi migrated (opencatalogi#1411). stackiq could not, and measuring why
is what produced this change: its setup walkthrough says "Click New and save an
organisation" and advances on `object-created`. Migrating that onto a read-only
schema would retire a working flow rather than move it. Every other app with an
`organization` schema has the same shape, because an app that only ever READ
organisations would not have declared a schema for them.

So the read-only projection cannot do the job it was built for.

## What changes

`OrganisationObjectSourceProvider` implements `WritableObjectSourceProvider`.
The dispatch it plugs into already exists and already gates correctly:
`SaveObject::delegateObjectSourceWrite()` delegates only when the schema
annotation carries `readOnly: false` AND the provider implements the interface.

**Create goes through `OrganisationService::createOrganisation()`**, not through
the mapper. That is what makes the write safe rather than a second, thinner path
to a tenant: slug generation, owner assignment, admin-user membership, the
admin-group RBAC grant and slug-collision recovery all still happen. The
provider applies the remaining identity fields afterwards, because
`createOrganisation()` takes only a name and a description.

**Update requires ownership.** Membership is enough to read the projection;
writing needs `OrganisationService::isOrganisationAdmin()`, which is the instance
admin or the organisation's owner. The projection and the rest of the app then
cannot disagree about who administers an organisation.

**Update does not follow a merge chain, although read does.** Following one on a
write would silently edit the survivor while the caller believes it is editing
the record it addressed.

**Delete refuses.** An organisation is the tenant boundary; deleting one through
the object API would orphan every object scoped to it, from a caller that thinks
it is removing a reference record. Merging is the operation that retires an
organisation, and it keeps stored references pointing at a record that still
owns something.

## The part that would have shipped inert

`SeedDirectoryVirtualSchemas::ensureSchema()` returns the moment the schema is
found. Setting `readOnly: false` only on the create branch would have reached
fresh installs and no existing instance, and `delegateObjectSourceWrite()` reads
exactly that annotation. The provider would implement the interface, the seed
would report success, and every write would still be refused with a generic
"read-only projection" message that names the provider rather than the
annotation.

So the seed now reconciles the flag on an existing schema too, touching only the
`readOnly` key and leaving the rest of the configuration as the instance has it.
A reconcile that fails logs at ERROR with the consequence spelled out, rather
than degrading into `run()`'s generic warning: the schema exists and works, it
just refuses writes, which is the kind of failure that reads as a different bug
entirely.

## What this unblocks, and what it does not

It unblocks stackiq's migration (stackiq#944 landed the resolution; the schema
retirement waits on this). It does not perform any migration itself. Each app
still needs its own change: a schema for the properties the projection has no
column for, the reading sites repointed, then
`openregister:organisations:adopt` and `openregister:schemas:prune-retired`.

## Verified against a live instance, not only in unit tests

Task 4.3 exists because a passing seed test proves the code and not the
migration. On a running instance whose `nc-organisation` was already seeded
`readOnly: true`:

- `occ maintenance:repair` flipped it to `readOnly: false`, and left `nc-user`,
  `nc-group`, `nc-contact`, `nc-event`, `nc-file`, `nc-card`,
  `nc-conversation` and `nc-task` read-only.
- `POST /api/objects/2/38` created a real organisation: uuid preserved as the
  object id, slug `e2e-probe-organisatie` generated, owner `admin` assigned, OIN
  applied. That is the lifecycle having run, not a row inserted.
- `PUT` on it wrote `summary` through and read back changed.

## Two things this leaves standing

**A delete over HTTP is refused by the wrong thing.** `ObjectsController::destroy()`
resolves the uuid through `MagicMapper` before the object-source dispatch, and
answers 404 when the register/schema table does not exist. So `remove()` is
never reached and its message never surfaces. The organisation survives, which
is the outcome this change needs, but the refusal is an accident of routing
rather than the guard: it is a pre-existing gap shared with every read-only
projection, and it would stop refusing the moment that lookup learns about
virtual objects. The provider's refusal is what makes it durable, and it is
tested at that level.

**`remove()` refusing means an organisation created through the projection by
mistake cannot be removed through it.** That is deliberate, and the merge
command is the answer. If a "create and immediately undo" flow turns out to be
needed, it belongs behind the organisation lifecycle, not behind the object
API.
