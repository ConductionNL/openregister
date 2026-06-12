# Runtime Schema API

OpenRegister exposes runtime CRUD on `/api/schemas` and `/api/registers`
so a non-admin citizen developer (driven by an in-Nextcloud builder UI
such as OpenBuild's schema editor) can author a full data model without
shipping a PHP `_register.json` PR. This document captures the contract
that makes those routes correct for runtime use: cache invalidation,
DELETE safety, auto-Register provisioning on import, and the
slug-aware search helper.

## Cache invalidation on mutation

Every successful `POST` / `PUT` / `PATCH` / `DELETE` on a schema or a
register calls into the cache handler before the controller returns:

- `SchemaCacheHandler::invalidate(int $schemaId)` clears the
  in-process schema cache + the request-scoped `SchemaMapper::findCache`
  entry. The next read in the same PHP worker re-fetches from the DB.
- `RegisterCacheHandler::invalidate(int $registerId)` does the same
  for registers (registers don't have a persistent cache table; the
  request-scoped mapper cache is the relevant clearance target).

This closes the "create schema → immediately use it" loop that
runtime authoring requires.

## DELETE safety

```
DELETE /api/schemas/{id}
DELETE /api/registers/{id}
```

Without `?force=true`, the controller refuses to delete an entity that
still owns objects: returns HTTP 409 with body
`{ "error": "schema-has-objects", "objectCount": N }` (or
`register-has-objects` on registers). With `?force=true`, the delete
proceeds and a WARNING is logged with the calling user, the entity
slug, and the orphan-object count for audit-trail review.

## `importFromApp` auto-Register

When a configuration imported via `ImportHandler::importFromApp`
carries `x-openregister.type=application`, the handler now derives a
Register entity from `x-openregister.app` (slug), `info.title`
(title), and `info.description` (description). Lookup is idempotent
on `(slug, organisationId)` so re-imports update rather than
duplicate; schemas[] is unioned so already-attached schemas are
preserved across re-imports. Configurations of any other `x-openregister.type`
or without the marker are NOT auto-provisioned — the previous
behaviour is preserved.

## Slug-aware search helper

```php
$results = $objectService->searchObjectsBySlug(
    'openbuild',    // register slug
    'application',  // schema slug
    ['type' => 'service']
);
```

`ObjectService::searchObjects` requires numeric `@self.register` and
`@self.schema` for the fast path; passing slugs silently downgraded
the search before this spec landed. `searchObjectsBySlug()` resolves
both slugs through the mappers' standard organisation-filtered
`find()` and delegates to `searchObjects()`. An unknown slug
(either side) throws `OCP\AppFramework\Db\DoesNotExistException`
identifying which side failed; foreign-organisation slugs throw
the same exception (the resolver never crosses tenants).

## Smoke test reference

The `bootstrap-openbuild` smoke test (commit 3138e4c, openbuild repo)
documents the manual flow this spec was driven from. Re-running it
against this branch confirms:

1. `POST /api/configurations/import-from-app` of an `application`-type
   OAS produces a complete `(Configuration, Schemas, Register)` triple
   in one call — no manual `POST /api/registers` needed.
2. `searchObjectsBySlug('openbuild', 'application', [])` returns the
   seeded objects (pre-spec: zero results).
3. Editing a schema via `PUT /api/schemas/{id}` then re-listing
   immediately surfaces the new state in the same PHP worker (cache
   invalidation works).
